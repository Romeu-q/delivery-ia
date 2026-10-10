<?php

namespace App\Http\Controllers\Api;

use App\Analytics\PeriodResolver;
use App\Analytics\SalesAnalytics;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Indicadores do topo da tela e sugestões de perguntas. Não usa IA: números direto do banco.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, SalesAnalytics $analytics): JsonResponse
    {
        $store = $request->attributes->get('store');

        $data = Cache::remember("dashboard:{$store->id}", config('ai.analytics_cache_ttl'), function () use ($store, $analytics) {
            $now = CarbonImmutable::now();
            $period = (new PeriodResolver($now))->resolve('this_month');
            $kpis = $analytics->compare(
                $analytics->summary($store->id, $period),
                $analytics->summary($store->id, $period->previous()),
            );

            $inactive = $analytics->inactiveCustomersQuery($store->id, $now, 30)->count();
            $anomalies = $analytics->dailyAnomalies($store->id, $now, 7);

            return [
                'period' => $period->toArray(),
                'kpis' => [
                    'revenue' => $kpis['revenue'],
                    'orders' => $kpis['orders'],
                    'avg_ticket' => $kpis['avg_ticket'],
                    'unique_customers' => $kpis['unique_customers'],
                ],
                'alerts' => array_values(array_filter([
                    $kpis['revenue']['change_pct'] !== null && $kpis['revenue']['change_pct'] <= -10
                        ? ['type' => 'drop', 'text' => 'Faturamento abaixo do mesmo período do mês passado', 'question' => 'Por que meu faturamento caiu este mês?']
                        : null,
                    $inactive > 0
                        ? ['type' => 'customers', 'text' => "{$inactive} clientes sem pedir há mais de 30 dias", 'question' => 'Quais clientes não compram há mais de 30 dias?']
                        : null,
                    $anomalies !== []
                        ? ['type' => 'anomaly', 'text' => count($anomalies).' dia(s) fora do padrão na última semana', 'question' => 'Teve algum dia fora do normal nas últimas semanas?']
                        : null,
                ])),
            ];
        });

        return response()->json([
            'store' => ['name' => $store->name],
            'suggestions' => [
                'Quanto vendi este mês?',
                'Por que minhas vendas caíram?',
                'Qual foi meu melhor dia da semana?',
                'Quais produtos estão perdendo vendas?',
                'Quais são meus melhores clientes?',
                'Qual horário possui maior volume de pedidos?',
                'Me dê três ações para aumentar minhas vendas esta semana.',
                'Crie uma campanha para clientes que não compram há mais de 30 dias.',
            ],
        ] + $data);
    }
}
