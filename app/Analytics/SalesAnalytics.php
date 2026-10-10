<?php

namespace App\Analytics;

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Todas as consultas analíticas do assistente. São consultas FIXAS, parametrizadas
 * (bindings do query builder) e sempre filtradas pela loja: a IA escolhe qual método
 * chamar e com quais parâmetros validados, mas nunca escreve SQL.
 *
 * Definições usadas em todo o sistema:
 *  - faturamento = soma de orders.total (subtotal - desconto + taxa) dos pedidos não cancelados;
 *  - ticket médio = faturamento / quantidade de pedidos não cancelados.
 */
class SalesAnalytics
{
    private const WEEKDAYS = [1 => 'Domingo', 2 => 'Segunda', 3 => 'Terça', 4 => 'Quarta', 5 => 'Quinta', 6 => 'Sexta', 7 => 'Sábado'];

    public function summary(int $storeId, Period $period): array
    {
        $row = $this->validOrders($storeId, $period)
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue, COALESCE(SUM(discount), 0) AS discounts,
                COALESCE(SUM(delivery_fee), 0) AS delivery_fees, COUNT(DISTINCT customer_id) AS customers')
            ->first();

        $canceled = $this->ordersIn($storeId, $period)->where('status', OrderStatus::Canceled->value)->count();

        $newCustomers = DB::table('customers')
            ->where('store_id', $storeId)
            ->where('first_order_at', '>=', $period->start)
            ->where('first_order_at', '<', $period->end)
            ->count();

        $itemsSold = (int) DB::table('order_items')
            ->joinSub($this->validOrders($storeId, $period)->select('id'), 'o', 'o.id', '=', 'order_items.order_id')
            ->sum('order_items.quantity');

        $orders = (int) $row->orders;
        $revenue = (float) $row->revenue;
        $allOrders = $orders + $canceled;

        return [
            'revenue' => round($revenue, 2),
            'orders' => $orders,
            'avg_ticket' => $orders ? round($revenue / $orders, 2) : 0.0,
            'items_sold' => $itemsSold,
            'discounts' => round((float) $row->discounts, 2),
            'delivery_fees' => round((float) $row->delivery_fees, 2),
            'unique_customers' => (int) $row->customers,
            'new_customers' => $newCustomers,
            'returning_customers' => max(0, (int) $row->customers - $newCustomers),
            'canceled_orders' => $canceled,
            'cancel_rate_pct' => $allOrders ? round($canceled / $allOrders * 100, 1) : 0.0,
            'avg_orders_per_day' => round($orders / $period->days(), 1),
        ];
    }

    /** Compara cada métrica numérica com o período anterior, já com variação absoluta e percentual. */
    public function compare(array $current, array $previous): array
    {
        $result = [];
        foreach ($current as $metric => $value) {
            if (! is_numeric($value) || ! array_key_exists($metric, $previous)) {
                continue;
            }
            $result[$metric] = [
                'current' => $value,
                'previous' => $previous[$metric],
                'change' => round($value - $previous[$metric], 2),
                'change_pct' => self::pctChange($value, $previous[$metric]),
            ];
        }

        return $result;
    }

    public function timeseries(int $storeId, Period $period, string $granularity): array
    {
        $bucket = match ($granularity) {
            'month' => "DATE_FORMAT(ordered_at, '%Y-%m')",
            'week' => "DATE_FORMAT(DATE_SUB(DATE(ordered_at), INTERVAL WEEKDAY(ordered_at) DAY), '%Y-%m-%d')",
            default => "DATE_FORMAT(ordered_at, '%Y-%m-%d')",
        };

        return $this->validOrders($storeId, $period)
            ->selectRaw("{$bucket} AS bucket, COUNT(*) AS orders, SUM(total) AS revenue")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($r) => [
                'period' => $r->bucket,
                'orders' => (int) $r->orders,
                'revenue' => round((float) $r->revenue, 2),
                'avg_ticket' => round((float) $r->revenue / max(1, (int) $r->orders), 2),
            ])
            ->all();
    }

    public function byWeekday(int $storeId, Period $period): array
    {
        $rows = $this->validOrders($storeId, $period)
            ->selectRaw('DAYOFWEEK(ordered_at) AS dow, COUNT(*) AS orders, SUM(total) AS revenue, COUNT(DISTINCT DATE(ordered_at)) AS days')
            ->groupBy('dow')
            ->get()
            ->keyBy('dow');

        $result = [];
        foreach ([2, 3, 4, 5, 6, 7, 1] as $dow) {
            $r = $rows->get($dow);
            $days = $r ? (int) $r->days : 0;
            $result[] = [
                'weekday' => self::WEEKDAYS[$dow],
                'orders' => $r ? (int) $r->orders : 0,
                'revenue' => $r ? round((float) $r->revenue, 2) : 0.0,
                'avg_revenue_per_day' => $days ? round((float) $r->revenue / $days, 2) : 0.0,
                'avg_orders_per_day' => $days ? round((int) $r->orders / $days, 1) : 0.0,
            ];
        }

        return $result;
    }

    public function byHour(int $storeId, Period $period): array
    {
        return $this->validOrders($storeId, $period)
            ->selectRaw('HOUR(ordered_at) AS hour, COUNT(*) AS orders, SUM(total) AS revenue')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn ($r) => [
                'hour' => sprintf('%02dh', $r->hour),
                'orders' => (int) $r->orders,
                'revenue' => round((float) $r->revenue, 2),
            ])
            ->all();
    }

    public function topProducts(int $storeId, Period $period, int $limit, string $sortBy = 'revenue', ?string $category = null): array
    {
        return $this->productSales($storeId, $period)
            ->when($category, fn (Builder $q) => $q->where('p.category', $category))
            ->orderByDesc($sortBy === 'quantity' ? 'quantity' : 'revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'product' => $r->name,
                'category' => $r->category,
                'quantity' => (int) $r->quantity,
                'revenue' => round((float) $r->revenue, 2),
                'orders' => (int) $r->orders,
            ])
            ->all();
    }

    /**
     * Produtos que mais perderam (ou ganharam) vendas em relação ao período anterior.
     * Ignora produtos com volume muito baixo nos dois períodos para evitar "−100%" sem relevância.
     */
    public function productTrends(int $storeId, Period $period, string $direction, int $limit): array
    {
        $current = $this->productSales($storeId, $period)->get()->keyBy('id');
        $previous = $this->productSales($storeId, $period->previous())->get()->keyBy('id');

        $rows = [];
        foreach ($current->keys()->merge($previous->keys())->unique() as $id) {
            $cur = $current->get($id);
            $prev = $previous->get($id);
            $qCur = $cur ? (int) $cur->quantity : 0;
            $qPrev = $prev ? (int) $prev->quantity : 0;
            if ($qCur + $qPrev < 4) {
                continue;
            }
            $rows[] = [
                'product' => ($cur ?? $prev)->name,
                'category' => ($cur ?? $prev)->category,
                'quantity_current' => $qCur,
                'quantity_previous' => $qPrev,
                'quantity_change' => $qCur - $qPrev,
                'quantity_change_pct' => self::pctChange($qCur, $qPrev),
                'revenue_current' => round((float) ($cur->revenue ?? 0), 2),
                'revenue_previous' => round((float) ($prev->revenue ?? 0), 2),
                'revenue_change_pct' => self::pctChange((float) ($cur->revenue ?? 0), (float) ($prev->revenue ?? 0)),
            ];
        }

        usort($rows, fn ($a, $b) => $direction === 'growing'
            ? $b['quantity_change'] <=> $a['quantity_change']
            : $a['quantity_change'] <=> $b['quantity_change']);

        return array_values(array_filter(
            array_slice($rows, 0, $limit),
            fn ($r) => $direction === 'growing' ? $r['quantity_change'] > 0 : $r['quantity_change'] < 0,
        ));
    }

    public function categoryPerformance(int $storeId, Period $period): array
    {
        $query = fn (Period $p) => DB::table('order_items as oi')
            ->joinSub($this->validOrders($storeId, $p)->select('id'), 'o', 'o.id', '=', 'oi.order_id')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->selectRaw('p.category, SUM(oi.quantity) AS quantity, SUM(oi.total) AS revenue')
            ->groupBy('p.category')
            ->get()
            ->keyBy('category');

        $current = $query($period);
        $previous = $query($period->previous());
        $totalRevenue = (float) $current->sum('revenue');

        return $current->keys()->merge($previous->keys())->unique()
            ->map(function ($category) use ($current, $previous, $totalRevenue) {
                $cur = $current->get($category);
                $prev = $previous->get($category);
                $revenue = (float) ($cur->revenue ?? 0);

                return [
                    'category' => $category,
                    'quantity_current' => (int) ($cur->quantity ?? 0),
                    'quantity_previous' => (int) ($prev->quantity ?? 0),
                    'quantity_change_pct' => self::pctChange((int) ($cur->quantity ?? 0), (int) ($prev->quantity ?? 0)),
                    'revenue_current' => round($revenue, 2),
                    'revenue_previous' => round((float) ($prev->revenue ?? 0), 2),
                    'revenue_change_pct' => self::pctChange($revenue, (float) ($prev->revenue ?? 0)),
                    'share_of_revenue_pct' => $totalRevenue ? round($revenue / $totalRevenue * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('revenue_current')
            ->values()
            ->all();
    }

    public function topCustomers(int $storeId, Period $period, int $limit, string $sortBy = 'revenue'): array
    {
        return $this->validOrders($storeId, $period)
            ->join('customers as c', 'c.id', '=', 'orders.customer_id')
            ->selectRaw('c.id, c.name, c.phone, c.last_order_at, COUNT(*) AS orders, SUM(orders.total) AS revenue')
            ->groupBy('c.id', 'c.name', 'c.phone', 'c.last_order_at')
            ->orderByDesc($sortBy === 'orders' ? 'orders' : 'revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'customer' => $r->name,
                'phone' => self::maskPhone($r->phone),
                'orders' => (int) $r->orders,
                'revenue' => round((float) $r->revenue, 2),
                'avg_ticket' => round((float) $r->revenue / max(1, (int) $r->orders), 2),
                'last_order' => CarbonImmutable::parse($r->last_order_at)->format('d/m/Y'),
            ])
            ->all();
    }

    /** Clientes sem pedidos há pelo menos N dias (usa a coluna desnormalizada last_order_at, indexada). */
    public function inactiveCustomersQuery(int $storeId, CarbonImmutable $now, int $minDays, ?int $maxDays = null): Builder
    {
        return DB::table('customers')
            ->where('customers.store_id', $storeId)
            ->where('customers.last_order_at', '<', $now->subDays($minDays))
            ->when($maxDays, fn (Builder $q) => $q->where('customers.last_order_at', '>=', $now->subDays($maxDays)));
    }

    public function inactiveCustomers(int $storeId, CarbonImmutable $now, int $minDays, ?int $maxDays, int $limit): array
    {
        $base = $this->inactiveCustomersQuery($storeId, $now, $minDays, $maxDays);
        $total = (clone $base)->count();

        // Lifetime value dos inativos: prioriza quem mais gastava (maior valor de reconquista)
        $sample = (clone $base)
            ->join('orders as o', 'o.customer_id', '=', 'customers.id')
            ->whereIn('o.status', OrderStatus::revenueStatuses())
            ->selectRaw('customers.name, customers.phone, customers.last_order_at, COUNT(o.id) AS orders, SUM(o.total) AS revenue')
            ->groupBy('customers.id', 'customers.name', 'customers.phone', 'customers.last_order_at')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        $lifetime = (clone $base)
            ->join('orders as o', 'o.customer_id', '=', 'customers.id')
            ->whereIn('o.status', OrderStatus::revenueStatuses())
            ->selectRaw('COALESCE(SUM(o.total), 0) AS revenue, COUNT(o.id) AS orders')
            ->first();

        return [
            'min_days_without_order' => $minDays,
            'max_days_without_order' => $maxDays,
            'total_customers' => $total,
            'their_historical_revenue' => round((float) $lifetime->revenue, 2),
            'their_historical_orders' => (int) $lifetime->orders,
            'top_customers_by_value' => $sample->map(fn ($r) => [
                'customer' => $r->name,
                'phone' => self::maskPhone($r->phone),
                'days_without_order' => (int) CarbonImmutable::parse($r->last_order_at)->diffInDays($now),
                'orders' => (int) $r->orders,
                'revenue' => round((float) $r->revenue, 2),
            ])->all(),
        ];
    }

    /** Clientes que compraram no período anterior e ainda não compraram no período atual. */
    public function churnedCustomers(int $storeId, Period $period, int $limit): array
    {
        $previous = $period->previous();
        $currentBuyers = $this->validOrders($storeId, $period)->select('customer_id');

        $lost = $this->validOrders($storeId, $previous)
            ->join('customers as c', 'c.id', '=', 'orders.customer_id')
            ->whereNotIn('orders.customer_id', $currentBuyers)
            ->selectRaw('c.name, c.phone, c.last_order_at, COUNT(*) AS orders, SUM(orders.total) AS revenue')
            ->groupBy('c.id', 'c.name', 'c.phone', 'c.last_order_at')
            ->orderByDesc('revenue')
            ->get();

        $previousBuyers = $this->validOrders($storeId, $previous)->distinct()->count('customer_id');

        return [
            'previous_period' => $previous->toArray(),
            'customers_in_previous_period' => $previousBuyers,
            'customers_not_returned' => $lost->count(),
            'not_returned_pct' => $previousBuyers ? round($lost->count() / $previousBuyers * 100, 1) : 0.0,
            'revenue_from_them_in_previous_period' => round((float) $lost->sum('revenue'), 2),
            'top_customers_not_returned' => $lost->take($limit)->map(fn ($r) => [
                'customer' => $r->name,
                'phone' => self::maskPhone($r->phone),
                'orders_previous_period' => (int) $r->orders,
                'revenue_previous_period' => round((float) $r->revenue, 2),
                'last_order' => CarbonImmutable::parse($r->last_order_at)->format('d/m/Y'),
            ])->values()->all(),
        ];
    }

    /**
     * Detecção simples de anomalias: compara o faturamento de cada um dos últimos N dias
     * com a média do mesmo dia da semana nas 8 semanas anteriores (z-score).
     */
    public function dailyAnomalies(int $storeId, CarbonImmutable $now, int $days = 21): array
    {
        $from = $now->startOfDay()->subDays($days + 56);
        $daily = $this->validOrders($storeId, new Period($from, $now->startOfDay(), ''))
            ->selectRaw('DATE(ordered_at) AS day, SUM(total) AS revenue, COUNT(*) AS orders')
            ->groupBy('day')
            ->pluck('revenue', 'day')
            ->map(fn ($v) => (float) $v);

        $anomalies = [];
        for ($d = $now->startOfDay()->subDays($days); $d->lt($now->startOfDay()); $d = $d->addDay()) {
            $baseline = [];
            for ($w = 1; $w <= 8; $w++) {
                $baseline[] = $daily->get($d->subWeeks($w)->toDateString(), 0.0);
            }
            $mean = array_sum($baseline) / count($baseline);
            $std = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $baseline)) / count($baseline));
            $value = $daily->get($d->toDateString(), 0.0);

            if ($std > 0 && abs($value - $mean) / $std >= 2 && $mean > 0) {
                $anomalies[] = [
                    'date' => $d->format('d/m/Y'),
                    'weekday' => self::WEEKDAYS[$d->dayOfWeek + 1],
                    'revenue' => round($value, 2),
                    'expected_revenue' => round($mean, 2),
                    'deviation_pct' => self::pctChange($value, $mean),
                    'type' => $value > $mean ? 'acima do normal' : 'abaixo do normal',
                ];
            }
        }

        return $anomalies;
    }

    public static function pctChange(float|int $current, float|int $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    public static function maskPhone(string $phone): string
    {
        return '(**) *****-'.substr(preg_replace('/\D/', '', $phone), -4);
    }

    private function ordersIn(int $storeId, Period $period): Builder
    {
        return DB::table('orders')
            ->where('orders.store_id', $storeId)
            ->where('orders.ordered_at', '>=', $period->start)
            ->where('orders.ordered_at', '<', $period->end);
    }

    private function validOrders(int $storeId, Period $period): Builder
    {
        return $this->ordersIn($storeId, $period)->whereIn('orders.status', OrderStatus::revenueStatuses());
    }

    private function productSales(int $storeId, Period $period): Builder
    {
        return DB::table('order_items as oi')
            ->joinSub($this->validOrders($storeId, $period)->select('id'), 'o', 'o.id', '=', 'oi.order_id')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->selectRaw('p.id, p.name, p.category, SUM(oi.quantity) AS quantity, SUM(oi.total) AS revenue, COUNT(DISTINCT oi.order_id) AS orders')
            ->groupBy('p.id', 'p.name', 'p.category');
    }
}
