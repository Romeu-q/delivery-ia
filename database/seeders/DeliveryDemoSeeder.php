<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Gera ~14 meses de operação fictícia de um delivery, SEMPRE relativa à data atual,
 * para que "este mês" e "mês passado" tenham dados em qualquer dia em que o seed rodar.
 *
 * Padrões propositais (para a IA ter o que interpretar):
 *  - crescimento gradual até o mês passado e pico em dezembro;
 *  - queda de volume no mês atual (~15-20% menos pedidos/dia);
 *  - "Marmitas Executivas" perdendo espaço no mês atual e "Saudável" crescendo;
 *  - sexta/sábado/domingo mais fortes; picos no almoço e no jantar;
 *  - um grupo de bons clientes que parou de comprar recentemente (churn).
 *
 * Usa semente fixa: a mesma data gera sempre a mesma base.
 */
class DeliveryDemoSeeder extends Seeder
{
    private const SEED = 20261009;

    private const BASE_ORDERS_PER_DAY = 5.6;

    private const HISTORY_DAYS = 425;

    /** [nome, categoria, preço, refeição: lunch|dinner|any|drink|dessert] */
    private const MENU = [
        ['Marmita Executiva de Frango Grelhado', 'Marmitas Executivas', 26.90, 'lunch'],
        ['Marmita Executiva de Carne de Panela', 'Marmitas Executivas', 29.90, 'lunch'],
        ['Marmita Executiva de Bife Acebolado', 'Marmitas Executivas', 31.90, 'lunch'],
        ['Marmita Executiva de Peixe', 'Marmitas Executivas', 32.90, 'lunch'],
        ['Feijoada Individual', 'Marmitas Executivas', 34.90, 'lunch'],
        ['Bowl de Frango com Quinoa', 'Saudável', 33.90, 'lunch'],
        ['Salada Caesar com Frango', 'Saudável', 28.90, 'lunch'],
        ['Wrap Integral de Atum', 'Saudável', 24.90, 'any'],
        ['Poke de Salmão', 'Saudável', 42.90, 'any'],
        ['Pizza Calabresa Grande', 'Pizzas', 59.90, 'dinner'],
        ['Pizza Margherita Grande', 'Pizzas', 57.90, 'dinner'],
        ['Pizza Frango com Catupiry Grande', 'Pizzas', 64.90, 'dinner'],
        ['Pizza Portuguesa Grande', 'Pizzas', 62.90, 'dinner'],
        ['Pizza Quatro Queijos Grande', 'Pizzas', 66.90, 'dinner'],
        ['X-Burguer Artesanal', 'Lanches', 29.90, 'dinner'],
        ['X-Bacon Duplo', 'Lanches', 36.90, 'dinner'],
        ['Smash Burguer', 'Lanches', 27.90, 'dinner'],
        ['Hot Dog Completo', 'Lanches', 19.90, 'dinner'],
        ['Batata Frita com Cheddar e Bacon', 'Porções', 34.90, 'dinner'],
        ['Isca de Frango Empanada', 'Porções', 39.90, 'dinner'],
        ['Anéis de Cebola', 'Porções', 26.90, 'dinner'],
        ['Refrigerante Lata', 'Bebidas', 6.50, 'drink'],
        ['Refrigerante 2L', 'Bebidas', 14.90, 'drink'],
        ['Suco Natural 500ml', 'Bebidas', 11.90, 'drink'],
        ['Água Mineral', 'Bebidas', 4.50, 'drink'],
        ['Brownie com Sorvete', 'Sobremesas', 18.90, 'dessert'],
        ['Pudim de Leite', 'Sobremesas', 12.90, 'dessert'],
        ['Açaí 500ml', 'Sobremesas', 22.90, 'dessert'],
    ];

    /** Multiplicador de volume por dia da semana (ISO: 1 = segunda). */
    private const WEEKDAY_FACTOR = [1 => 0.72, 2 => 0.80, 3 => 0.90, 4 => 1.00, 5 => 1.30, 6 => 1.45, 7 => 1.25];

    public function run(): void
    {
        mt_srand(self::SEED);
        $faker = FakerFactory::create('pt_BR');
        $faker->seed(self::SEED);

        $now = CarbonImmutable::now();
        $start = $now->startOfDay()->subDays(self::HISTORY_DAYS);

        // Reaproveita a loja se já existir: a renovação diária troca só pedidos, clientes e produtos
        $store = Store::query()->firstOrCreate(['slug' => 'sabor-da-vila'], ['name' => 'Sabor da Vila Delivery']);

        $products = $this->seedProducts($store->id, $now);
        $customers = $this->buildCustomers($store->id, $faker, $start, $now);

        $orders = [];
        $items = [];
        $orderId = 0;

        for ($day = $start; $day->lte($now); $day = $day->addDay()) {
            $expected = self::BASE_ORDERS_PER_DAY
                * $this->trendFactor($day, $now)
                * self::WEEKDAY_FACTOR[$day->dayOfWeekIso];
            $count = max(0, (int) round($expected + $this->noise() * sqrt($expected)));

            $active = array_values(array_filter(
                $customers,
                fn ($c) => $c['joined_at']->lte($day) && ($c['churn_at'] === null || $c['churn_at']->gt($day)),
            ));
            if ($active === []) {
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                $orderedAt = $this->randomTime($day);
                if ($orderedAt->gt($now)) {
                    continue;
                }

                $customer = $this->weightedPick($active);
                $orderId++;
                [$lineItems, $subtotal] = $this->buildItems($orderId, $products, $orderedAt, $now);

                $discount = 0.0;
                if ($this->chance(0.18)) {
                    $discount = round($subtotal * ($this->chance(0.6) ? 0.10 : 0.15), 2);
                }
                $fee = $subtotal >= 90 ? 0.0 : [5.99, 7.99, 9.99][mt_rand(0, 2)];

                $orders[] = [
                    'id' => $orderId,
                    'store_id' => $store->id,
                    'customer_id' => $customer['id'],
                    'ordered_at' => $orderedAt->toDateTimeString(),
                    'status' => $this->status($orderedAt, $now),
                    'payment_method' => $this->paymentMethod(),
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'delivery_fee' => $fee,
                    'total' => round($subtotal - $discount + $fee, 2),
                    'created_at' => $orderedAt->toDateTimeString(),
                    'updated_at' => $orderedAt->toDateTimeString(),
                ];
                array_push($items, ...$lineItems);
            }
        }

        DB::table('customers')->insert(array_map(fn ($c) => [
            'id' => $c['id'],
            'store_id' => $store->id,
            'name' => $c['name'],
            'phone' => $c['phone'],
            'created_at' => $c['joined_at']->toDateTimeString(),
            'updated_at' => $c['joined_at']->toDateTimeString(),
        ], $customers));

        foreach (array_chunk($orders, 500) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
        foreach (array_chunk($items, 1000) as $chunk) {
            DB::table('order_items')->insert($chunk);
        }

        // Datas de primeiro/último pedido calculadas a partir dos pedidos reais
        DB::statement('
            UPDATE customers c
            JOIN (SELECT customer_id, MIN(ordered_at) AS first_at, MAX(ordered_at) AS last_at
                  FROM orders GROUP BY customer_id) o ON o.customer_id = c.id
            SET c.first_order_at = o.first_at, c.last_order_at = o.last_at
        ');
        DB::table('customers')->whereNull('first_order_at')->delete();

        $this->command?->info(sprintf('Seed concluído: %d pedidos, %d itens, %d clientes.',
            count($orders), count($items), DB::table('customers')->count()));
    }

    private function seedProducts(int $storeId, CarbonImmutable $now): array
    {
        $products = [];
        foreach (self::MENU as $i => [$name, $category, $price, $meal]) {
            $products[] = [
                'id' => $i + 1,
                'store_id' => $storeId,
                'name' => $name,
                'category' => $category,
                'price' => $price,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('products')->insert($products);

        return array_map(fn ($p, $menu) => $p + ['meal' => $menu[3]], $products, self::MENU);
    }

    /**
     * Clientes com data de entrada (aquisição ao longo do tempo), peso de frequência
     * (poucos clientes compram muito) e, para alguns, data em que pararam de comprar.
     */
    private function buildCustomers(int $storeId, $faker, CarbonImmutable $start, CarbonImmutable $now): array
    {
        $customers = [];
        $total = 420;

        for ($id = 1; $id <= $total; $id++) {
            // 35% já existiam no início; os demais chegam ao longo do período
            $joined = $this->chance(0.35)
                ? $start
                : $start->addDays(mt_rand(0, self::HISTORY_DAYS - 5));

            // Distribuição de Pareto: ~20% dos clientes concentram boa parte dos pedidos
            $weight = 1 / pow(mt_rand(1, 1000) / 1000, 0.85);

            $churn = null;
            if ($this->chance(0.18)) {
                $churn = $joined->addDays(mt_rand(30, 300));
            }

            $customers[] = [
                'id' => $id,
                'name' => $faker->name(),
                'phone' => sprintf('(%02d) 9%04d-%04d', [11, 11, 11, 19, 21, 31, 41][mt_rand(0, 6)], mt_rand(1000, 9999), mt_rand(0, 9999)),
                'joined_at' => $joined,
                'churn_at' => $churn,
                'weight' => min($weight, 12),
            ];
        }

        // Grupo de bons clientes que deixou de comprar entre 20 e 50 dias atrás
        $loyal = array_keys(array_filter($customers, fn ($c) => $c['weight'] > 3 && $c['churn_at'] === null && $c['joined_at']->lt($now->subMonths(4))));
        foreach (array_slice($loyal, 0, 30) as $key) {
            $customers[$key]['churn_at'] = $now->subDays(mt_rand(20, 50));
        }

        return $customers;
    }

    /** Tendência mensal: crescimento até o mês passado, pico em dezembro e queda no mês atual. */
    private function trendFactor(CarbonImmutable $day, CarbonImmutable $now): float
    {
        $monthsAgo = ($now->year - $day->year) * 12 + ($now->month - $day->month);

        if ($monthsAgo === 0) {
            return 1.12 * 0.86;
        }

        $factor = 1.12 - ($monthsAgo - 1) * 0.045;

        if ($day->month === 12) {
            $factor *= 1.18;
        }
        if ($day->month === 1 || $day->month === 2) {
            $factor *= 0.92;
        }

        return $factor;
    }

    private function buildItems(int $orderId, array $products, CarbonImmutable $at, CarbonImmutable $now): array
    {
        $isLunch = $at->hour < 16;
        $currentMonth = $at->isSameMonth($now);
        $monthsAgo = ($now->year - $at->year) * 12 + ($now->month - $at->month);

        $byMeal = fn (array $meals, ?string $category = null, ?string $exclude = null) => array_values(array_filter(
            $products,
            fn ($p) => in_array($p['meal'], $meals, true)
                && ($category === null || $p['category'] === $category)
                && ($exclude === null || $p['category'] !== $exclude),
        ));

        $lines = [];
        if ($isLunch) {
            // Saudável ganha espaço nos últimos meses; marmitas perdem no mês atual
            $healthyShare = $monthsAgo <= 3 ? 0.22 + (3 - $monthsAgo) * 0.03 : 0.22;
            if ($currentMonth) {
                $healthyShare = 0.58;
            }
            $pool = $this->chance($healthyShare)
                ? $byMeal(['lunch', 'any'], 'Saudável')
                : $byMeal(['lunch'], 'Marmitas Executivas');
            $mains = mt_rand(1, 100) <= 75 ? 1 : 2;
        } else {
            $pool = $byMeal(['dinner', 'any']);
            $mains = mt_rand(1, 100) <= 60 ? 1 : (mt_rand(1, 100) <= 80 ? 2 : 3);
        }

        for ($i = 0; $i < $mains; $i++) {
            $lines[] = $pool[mt_rand(0, count($pool) - 1)];
        }
        if ($this->chance($isLunch ? 0.45 : 0.65)) {
            $drinks = $byMeal(['drink']);
            $lines[] = $drinks[mt_rand(0, count($drinks) - 1)];
        }
        if ($this->chance($isLunch ? 0.12 : 0.25)) {
            $desserts = $byMeal(['dessert']);
            $lines[] = $desserts[mt_rand(0, count($desserts) - 1)];
        }

        // Agrupa produtos repetidos em uma linha com quantidade
        $grouped = [];
        foreach ($lines as $p) {
            $grouped[$p['id']] ??= ['product' => $p, 'quantity' => 0];
            $grouped[$p['id']]['quantity']++;
        }

        $rows = [];
        $subtotal = 0.0;
        foreach ($grouped as $line) {
            $lineTotal = round($line['product']['price'] * $line['quantity'], 2);
            $subtotal += $lineTotal;
            $rows[] = [
                'order_id' => $orderId,
                'product_id' => $line['product']['id'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['product']['price'],
                'total' => $lineTotal,
            ];
        }

        return [$rows, round($subtotal, 2)];
    }

    /** Almoço (11h–14h) e jantar (18h–23h) concentram os pedidos; fim de semana puxa para o jantar. */
    private function randomTime(CarbonImmutable $day): CarbonImmutable
    {
        $weekend = $day->dayOfWeekIso >= 5;
        $roll = mt_rand(1, 100);
        $lunchShare = $weekend ? 32 : 46;

        if ($roll <= $lunchShare) {
            $minutes = $this->bell(11 * 60, 14 * 60, 12 * 60 + 15);
        } elseif ($roll <= 95) {
            $minutes = $this->bell(18 * 60, 23 * 60, 20 * 60);
        } else {
            $minutes = mt_rand(15 * 60, 17 * 60 + 59);
        }

        return $day->addMinutes($minutes)->addSeconds(mt_rand(0, 59));
    }

    /** Valor entre min e max concentrado perto do pico (média de duas uniformes). */
    private function bell(int $min, int $max, int $peak): int
    {
        $a = mt_rand($min, $max);
        $b = mt_rand($min, $max);
        $value = (int) round(($a + $b + 2 * $peak) / 4);

        return max($min, min($max, $value));
    }

    private function status(CarbonImmutable $orderedAt, CarbonImmutable $now): string
    {
        $minutesAgo = $orderedAt->diffInMinutes($now);

        if ($this->chance(0.045)) {
            return OrderStatus::Canceled->value;
        }

        return match (true) {
            $minutesAgo < 15 => OrderStatus::Pending->value,
            $minutesAgo < 35 => OrderStatus::Preparing->value,
            $minutesAgo < 70 => OrderStatus::OutForDelivery->value,
            default => OrderStatus::Delivered->value,
        };
    }

    private function paymentMethod(): string
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 45 => 'pix',
            $roll <= 80 => 'credit_card',
            $roll <= 92 => 'debit_card',
            default => 'cash',
        };
    }

    private function weightedPick(array $customers): array
    {
        $sum = array_sum(array_column($customers, 'weight'));
        $target = mt_rand() / mt_getrandmax() * $sum;

        foreach ($customers as $customer) {
            $target -= $customer['weight'];
            if ($target <= 0) {
                return $customer;
            }
        }

        return end($customers);
    }

    private function chance(float $probability): bool
    {
        return mt_rand() / mt_getrandmax() < $probability;
    }

    /** Ruído aproximadamente normal (soma de uniformes). */
    private function noise(): float
    {
        return (mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() - 1.5) * 1.4;
    }
}
