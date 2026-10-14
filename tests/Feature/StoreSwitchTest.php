<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesDeliveryData;
use Tests\TestCase;

class StoreSwitchTest extends TestCase
{
    use CreatesDeliveryData, RefreshDatabase;

    public function test_selected_store_is_kept_in_the_session_and_scopes_everything(): void
    {
        $first = $this->store('Loja A');
        $second = $this->store('Loja B');
        config(['app.demo_store_id' => $first->id]);

        $this->getJson('/api/dashboard')->assertJsonPath('store.name', 'Loja A');

        $this->postJson('/api/stores/select', ['store_id' => $second->id])->assertOk();

        $this->getJson('/api/dashboard')->assertJsonPath('store.name', 'Loja B');
        $this->getJson('/api/stores')->assertJsonPath('current_id', $second->id)->assertJsonCount(2, 'data');
    }

    public function test_conversations_of_one_store_do_not_appear_in_another(): void
    {
        Http::fake(['*' => Http::response('data: '.json_encode([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Olá!']]], 'finishReason' => 'STOP']],
        ])."\n\n")]);
        config(['ai.gemini.api_key' => 'test-key']);

        $first = $this->store('Loja A');
        $second = $this->store('Loja B');
        config(['app.demo_store_id' => $first->id]);

        $this->post('/api/chat', ['message' => 'Oi'])->streamedContent();
        $this->getJson('/api/conversations')->assertJsonCount(1, 'data');

        $this->postJson('/api/stores/select', ['store_id' => $second->id]);
        $this->getJson('/api/conversations')->assertJsonCount(0, 'data');
    }

    public function test_only_existing_stores_can_be_selected(): void
    {
        $this->store('Loja A');

        $this->postJson('/api/stores/select', ['store_id' => 999999])->assertUnprocessable();
        $this->postJson('/api/stores/select', ['store_id' => '1 OR 1=1'])->assertUnprocessable();
    }
}
