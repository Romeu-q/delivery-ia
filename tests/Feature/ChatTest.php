<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesDeliveryData;
use Tests\TestCase;

/**
 * O provedor de IA é simulado APENAS nos testes (Http::fake), para validar o fluxo
 * completo sem custo e de forma determinística. Em execução real a API é sempre chamada.
 */
class ChatTest extends TestCase
{
    use CreatesDeliveryData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-15 20:00:00'));

        config([
            'ai.gemini.api_key' => 'test-key',
            'ai.models' => ['modelo-principal', 'modelo-reserva'],
            'ai.pricing.modelo-principal' => ['input' => 1.0, 'output' => 2.0],
        ]);

        $store = $this->store('Sabor Teste');
        config(['app.demo_store_id' => $store->id]);
        $pizza = $this->product($store, 'Pizza', 'Pizzas', 50);
        $this->order($this->customer($store), '2026-03-05 20:00:00', [[$pizza, 1]], fee: 5);
    }

    private static function sse(array ...$chunks): string
    {
        return implode('', array_map(fn ($c) => 'data: '.json_encode($c)."\r\n\r\n", $chunks));
    }

    private static function functionCall(string $name, array $args): string
    {
        return self::sse([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => $name, 'args' => $args]]]]]],
            'usageMetadata' => ['promptTokenCount' => 1000, 'candidatesTokenCount' => 20],
        ]);
    }

    private static function text(string ...$pieces): string
    {
        $chunks = array_map(fn ($p) => ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $p]]]]]], $pieces);
        $chunks[] = ['candidates' => [['finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 1500, 'candidatesTokenCount' => 50]];

        return self::sse(...$chunks);
    }

    public function test_question_flows_through_tools_and_streams_a_verified_answer(): void
    {
        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push(self::functionCall('get_sales_summary', ['period' => 'this_month']))
            ->push(self::text('Você faturou **R$ 55,00** ', 'em 1 pedido este mês.'));

        $response = $this->post('/api/chat', ['message' => 'Quanto vendi este mês?']);

        $response->assertOk();
        $stream = $response->streamedContent();
        $this->assertStringContainsString('event: conversation', $stream);
        $this->assertStringContainsString('event: tool', $stream);
        $this->assertStringContainsString('event: delta', $stream);
        $this->assertStringContainsString('event: done', $stream);

        $answer = Message::query()->where('role', 'assistant')->sole();
        $this->assertSame('Você faturou **R$ 55,00** em 1 pedido este mês.', $answer->content);
        $this->assertSame('verified', $answer->meta['verification']['status']);
        $this->assertSame('get_sales_summary', $answer->meta['tools'][0]['name']);
        $this->assertSame(2500, $answer->prompt_tokens);
        $this->assertSame(70, $answer->output_tokens);
        $this->assertEqualsWithDelta(0.00264, $answer->cost_usd, 0.000001);

        // A segunda chamada ao modelo leva o resultado do banco como functionResponse
        Http::assertSentCount(2);
        $second = Http::recorded()[1][0];
        $functionResponse = $second->data()['contents'][2]['parts'][0]['functionResponse'];
        $this->assertSame(55.0, $functionResponse['response']['metrics']['revenue']['current']);
        // E a chave vai no header, nunca na URL
        $this->assertStringNotContainsString('test-key', $second->url());
        $this->assertSame('test-key', $second->header('x-goog-api-key')[0]);
    }

    public function test_falls_back_to_the_secondary_model_when_the_primary_is_unavailable(): void
    {
        Http::fake([
            '*modelo-principal*' => Http::response(['error' => ['message' => 'overloaded']], 503),
            '*modelo-reserva*' => Http::response(self::text('Olá! Como posso ajudar?')),
        ]);

        $this->post('/api/chat', ['message' => 'Oi'])->assertOk()->streamedContent();

        $answer = Message::query()->where('role', 'assistant')->sole();
        $this->assertSame('modelo-reserva', $answer->model);
        $this->assertSame('ok', $answer->status);
    }

    public function test_provider_failure_returns_a_friendly_error_event(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'quota']], 429)]);

        $stream = $this->post('/api/chat', ['message' => 'Quanto vendi?'])->streamedContent();

        $this->assertStringContainsString('event: error', $stream);
        $this->assertStringContainsString('muitas solicita', $stream);
        $this->assertStringNotContainsString('quota', $stream);
        $this->assertSame('error', Message::query()->where('role', 'assistant')->sole()->status);
    }

    public function test_input_is_validated(): void
    {
        Http::fake();

        $this->postJson('/api/chat', ['message' => ''])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->postJson('/api/chat', ['message' => str_repeat('a', 501)])->assertUnprocessable();
        $this->postJson('/api/chat', ['message' => 'Oi', 'conversation_id' => '1 OR 1=1'])->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_visitors_cannot_access_other_visitors_conversations(): void
    {
        Http::fake();
        $foreign = Conversation::query()->create([
            'store_id' => config('app.demo_store_id'),
            'visitor_id' => 'outro-visitante',
            'title' => 'Privada',
        ]);

        $this->getJson("/api/conversations/{$foreign->id}")->assertNotFound();
        $this->postJson('/api/chat', ['message' => 'Oi', 'conversation_id' => $foreign->id])->assertNotFound();
        $this->getJson('/api/conversations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_chat_is_rate_limited(): void
    {
        config(['ai.rate_limit.per_minute' => 2]);
        Http::fake(['*' => Http::response(self::text('Ok.'))]);

        $this->post('/api/chat', ['message' => 'Pergunta 1'])->assertOk();
        $this->post('/api/chat', ['message' => 'Pergunta 2'])->assertOk();
        $this->postJson('/api/chat', ['message' => 'Pergunta 3'])->assertTooManyRequests();
    }
}
