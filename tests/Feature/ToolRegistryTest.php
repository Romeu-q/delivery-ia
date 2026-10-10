<?php

namespace Tests\Feature;

use App\AI\Tools\ToolContext;
use App\AI\Tools\ToolRegistry;
use App\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDeliveryData;
use Tests\TestCase;

class ToolRegistryTest extends TestCase
{
    use CreatesDeliveryData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-15 20:00:00'));
    }

    private function context(int $storeId): ToolContext
    {
        return new ToolContext($storeId, CarbonImmutable::now());
    }

    public function test_unknown_tools_are_refused(): void
    {
        $result = app(ToolRegistry::class)->execute('run_sql', ['query' => 'DELETE FROM orders'], $this->context(1));

        $this->assertTrue($result->error);
        $this->assertStringContainsString('não existe', $result->data['error']);
    }

    public function test_invalid_arguments_are_rejected_before_any_query(): void
    {
        $registry = app(ToolRegistry::class);

        $badPeriod = $registry->execute('get_sales_summary', ['period' => "this_month'; DROP TABLE orders; --"], $this->context(1));
        $badLimit = $registry->execute('get_top_products', ['limit' => 10000], $this->context(1));

        $this->assertTrue($badPeriod->error);
        $this->assertTrue($badLimit->error);
    }

    public function test_campaign_draft_audience_is_built_by_the_backend_for_the_current_store_only(): void
    {
        $store = $this->store('Minha');
        $other = $this->store('Outra');
        $p = $this->product($store, 'Pizza', 'Pizzas', 50);
        $po = $this->product($other, 'Pizza', 'Pizzas', 50);

        $this->order($this->customer($store, 'Antiga'), '2026-01-10 20:00:00', [[$p, 1]]);
        $this->order($this->customer($store, 'Recente'), '2026-03-14 20:00:00', [[$p, 1]]);
        $this->order($this->customer($other, 'De outra loja'), '2026-01-10 20:00:00', [[$po, 1]]);

        $result = app(ToolRegistry::class)->execute('create_campaign_draft', [
            'min_days_without_order' => 30,
            'campaign_name' => 'Volta pra gente',
            'message_title' => 'Sentimos sua falta!',
            'message_body' => 'Oi {nome}, faz tempo! Volte hoje e ganhe frete grátis.',
        ], $this->context($store->id));

        $this->assertFalse($result->error);
        $this->assertSame(1, $result->data['audience_count']);
        $this->assertSame('Oi Antiga, faz tempo! Volte hoje e ganhe frete grátis.', $result->campaign['preview']);

        $campaign = Campaign::query()->sole();
        $this->assertSame('draft', $campaign->status);
        $this->assertSame(['Antiga'], $campaign->customers()->pluck('name')->all());
    }

    public function test_actions_can_be_disabled_by_configuration(): void
    {
        config(['ai.allowed_actions' => []]);
        $this->app->forgetInstance(ToolRegistry::class);

        $registry = app(ToolRegistry::class);

        $this->assertNotContains('create_campaign_draft', $registry->names());
        $this->assertContains('get_sales_summary', $registry->names());
    }
}
