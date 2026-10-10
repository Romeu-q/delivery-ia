<?php

namespace Tests\Feature;

use App\Analytics\PeriodResolver;
use App\Analytics\SalesAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDeliveryData;
use Tests\TestCase;

class SalesAnalyticsTest extends TestCase
{
    use CreatesDeliveryData, RefreshDatabase;

    private SalesAnalytics $analytics;

    private PeriodResolver $periods;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-15 20:00:00'));
        $this->analytics = new SalesAnalytics;
        $this->periods = new PeriodResolver(CarbonImmutable::now());
    }

    public function test_summary_compares_with_the_same_days_of_previous_month(): void
    {
        $store = $this->store();
        $marmita = $this->product($store, 'Marmita', 'Marmitas Executivas', 30);
        $pizza = $this->product($store, 'Pizza', 'Pizzas', 60);
        $ana = $this->customer($store, 'Ana');
        $bia = $this->customer($store, 'Bia');

        // Mês anterior (dentro dos mesmos 15 dias): 2 pedidos, R$ 150
        $this->order($ana, '2026-02-03 12:00:00', [[$marmita, 1]]);                 // 30
        $this->order($bia, '2026-02-10 20:00:00', [[$pizza, 2]]);                   // 120
        // Fora da janela comparável (depois do dia 15/02): não entra
        $this->order($bia, '2026-02-20 20:00:00', [[$pizza, 5]]);
        // Mês atual: 1 pedido válido de R$ 55 (60 - 10 + 5) e 1 cancelado
        $this->order($ana, '2026-03-05 19:00:00', [[$pizza, 1]], discount: 10, fee: 5);
        $this->order($ana, '2026-03-06 19:00:00', [[$pizza, 3]], status: 'canceled');

        $period = $this->periods->resolve('this_month');
        $metrics = $this->analytics->compare(
            $this->analytics->summary($store->id, $period),
            $this->analytics->summary($store->id, $period->previous()),
        );

        $this->assertSame(55.0, $metrics['revenue']['current']);
        $this->assertSame(150.0, $metrics['revenue']['previous']);
        $this->assertSame(-63.3, $metrics['revenue']['change_pct']);
        $this->assertSame(1, $metrics['orders']['current']);
        $this->assertSame(2, $metrics['orders']['previous']);
        $this->assertSame(55.0, $metrics['avg_ticket']['current']);
        $this->assertSame(75.0, $metrics['avg_ticket']['previous']);
        $this->assertSame(1, $metrics['canceled_orders']['current']);
        $this->assertSame(10.0, $metrics['discounts']['current']);
    }

    public function test_queries_never_mix_data_from_other_stores(): void
    {
        $mine = $this->store('Minha');
        $other = $this->store('Outra');
        $p1 = $this->product($mine, 'Pizza', 'Pizzas', 50);
        $p2 = $this->product($other, 'Pizza', 'Pizzas', 50);

        $this->order($this->customer($mine), '2026-03-10 20:00:00', [[$p1, 1]]);
        $this->order($this->customer($other), '2026-03-10 20:00:00', [[$p2, 4]]);

        $period = $this->periods->resolve('this_month');

        $this->assertSame(50.0, $this->analytics->summary($mine->id, $period)['revenue']);
        $this->assertSame(1, $this->analytics->topProducts($mine->id, $period, 5)[0]['quantity']);
        $this->assertSame(1, $this->analytics->inactiveCustomersQuery($mine->id, CarbonImmutable::now(), 1)->count());
    }

    public function test_product_trends_lists_products_losing_sales(): void
    {
        $store = $this->store();
        $marmita = $this->product($store, 'Marmita', 'Marmitas Executivas', 30);
        $salada = $this->product($store, 'Salada', 'Saudável', 25);
        $c = $this->customer($store);

        $this->order($c, '2026-02-05 12:00:00', [[$marmita, 6], [$salada, 1]]);
        $this->order($c, '2026-03-05 12:00:00', [[$marmita, 2], [$salada, 4]]);

        $declining = $this->analytics->productTrends($store->id, $this->periods->resolve('this_month'), 'declining', 5);

        $this->assertCount(1, $declining);
        $this->assertSame('Marmita', $declining[0]['product']);
        $this->assertSame(-4, $declining[0]['quantity_change']);
        $this->assertSame(-66.7, $declining[0]['quantity_change_pct']);
    }

    public function test_inactive_and_churned_customers(): void
    {
        $store = $this->store();
        $p = $this->product($store, 'Lanche', 'Lanches', 20);
        $active = $this->customer($store, 'Ativa');
        $gone = $this->customer($store, 'Sumida');

        $this->order($active, '2026-02-20 20:00:00', [[$p, 1]]);
        $this->order($active, '2026-03-10 20:00:00', [[$p, 1]]);
        $this->order($gone, '2026-01-25 20:00:00', [[$p, 3]]);
        $this->order($gone, '2026-02-01 20:00:00', [[$p, 2]]);

        $inactive = $this->analytics->inactiveCustomers($store->id, CarbonImmutable::now(), 30, null, 5);
        $this->assertSame(1, $inactive['total_customers']);
        $this->assertSame('Sumida', $inactive['top_customers_by_value'][0]['customer']);
        $this->assertSame(100.0, $inactive['their_historical_revenue']);
        $this->assertSame('(**) *****-4321', $inactive['top_customers_by_value'][0]['phone']);

        // Compraram nos 30 dias anteriores e não voltaram nos últimos 30 dias
        $churn = $this->analytics->churnedCustomers($store->id, $this->periods->resolve('last_30_days'), 5);
        $this->assertSame(1, $churn['customers_not_returned']);
        $this->assertSame('Sumida', $churn['top_customers_not_returned'][0]['customer']);
    }
}
