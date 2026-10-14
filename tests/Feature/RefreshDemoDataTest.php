<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RefreshDemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_brings_orders_up_to_today_and_keeps_conversations(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 21:00:00'));
        $this->artisan('demo:refresh --force')->assertSuccessful();

        $store = Store::query()->where('slug', 'sabor-da-vila')->sole();
        Conversation::query()->create(['store_id' => $store->id, 'visitor_id' => 'v1', 'title' => 'Minha conversa']);

        // Dias depois, a base está velha: a renovação repõe os pedidos até a nova data
        $this->travelTo(CarbonImmutable::parse('2026-03-20 21:00:00'));
        $this->artisan('demo:refresh --if-stale')->assertSuccessful();

        $this->assertSame('2026-03-20', substr(DB::table('orders')->max('ordered_at'), 0, 10));
        $this->assertSame(3, Store::query()->count()); // renovar não duplica as lojas
        $this->assertSame(1, Conversation::query()->count());
        $this->assertGreaterThan(500, DB::table('orders')->count());
    }

    public function test_fresh_base_is_left_untouched(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 21:00:00'));
        $this->artisan('demo:refresh --force');
        $before = DB::table('orders')->max('id');

        $this->artisan('demo:refresh --if-stale')->expectsOutputToContain('nada a fazer')->assertSuccessful();

        $this->assertSame($before, DB::table('orders')->max('id'));
    }
}
