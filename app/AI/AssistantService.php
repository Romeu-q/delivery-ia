<?php

namespace App\AI;

use App\AI\Gemini\AiProviderException;
use App\AI\Gemini\GeminiClient;
use App\AI\Gemini\GeminiResponse;
use App\AI\Tools\ToolContext;
use App\AI\Tools\ToolRegistry;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orquestra uma pergunta:
 *  pergunta → Gemini (com a lista de ferramentas) → ferramentas consultam o MySQL →
 *  resultados voltam ao Gemini → resposta em streaming → verificação dos números → persistência.
 */
class AssistantService
{
    /** Rótulos exibidos na interface enquanto cada ferramenta roda. */
    public const TOOL_LABELS = [
        'get_sales_summary' => 'Consultando faturamento, pedidos e ticket médio',
        'get_sales_timeseries' => 'Montando a evolução das vendas',
        'get_sales_by_weekday' => 'Analisando vendas por dia da semana',
        'get_sales_by_hour' => 'Analisando pedidos por horário',
        'get_top_products' => 'Buscando os produtos mais vendidos',
        'get_product_trends' => 'Comparando vendas de cada produto',
        'get_category_performance' => 'Comparando categorias',
        'get_top_customers' => 'Buscando os melhores clientes',
        'get_inactive_customers' => 'Procurando clientes inativos',
        'get_churned_customers' => 'Identificando clientes que deixaram de comprar',
        'detect_anomalies' => 'Procurando dias fora do padrão',
        'create_campaign_draft' => 'Criando o público e o rascunho da campanha',
    ];

    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly ToolRegistry $tools,
        private readonly NumberGuard $guard,
    ) {}

    /**
     * @param  callable(string $event, array $data): void  $emit  envia eventos ao cliente (SSE)
     */
    public function ask(Conversation $conversation, string $question, callable $emit): Message
    {
        $startedAt = microtime(true);
        $now = CarbonImmutable::now();
        $context = new ToolContext($conversation->store_id, $now, $conversation->id);

        $contents = $this->history($conversation);
        $conversation->messages()->create(['role' => 'user', 'content' => $question]);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $question]]];

        $answer = '';
        $toolLog = [];
        $toolData = [];
        $charts = [];
        $campaign = null;
        $promptTokens = 0;
        $outputTokens = 0;
        $cost = 0.0;
        $model = null;

        $onText = function (string $text) use (&$answer, $emit) {
            $answer .= $text;
            $emit('delta', ['text' => $text]);
        };

        try {
            $maxRounds = config('ai.max_tool_rounds');
            for ($round = 1; $round <= $maxRounds; $round++) {
                // Texto de rodadas anteriores ("vou consultar...") fica separado do restante
                if ($answer !== '' && ! str_ends_with($answer, "\n")) {
                    $onText("\n\n");
                }

                $roundStarted = microtime(true);
                $response = $this->generate(
                    $this->payload($conversation, $now, $contents, lastRound: $round === $maxRounds),
                    $onText,
                );
                Log::debug('ai.round', [
                    'round' => $round,
                    'model' => $response->model,
                    'ms' => (int) round((microtime(true) - $roundStarted) * 1000),
                    'prompt_tokens' => $response->promptTokens,
                    'output_tokens' => $response->outputTokens,
                    'calls' => array_column($response->functionCalls(), 'name'),
                ]);
                $model = $response->model;
                $promptTokens += $response->promptTokens;
                $outputTokens += $response->outputTokens;
                $cost += $this->cost($response);

                $calls = $response->functionCalls();
                if ($calls === []) {
                    break;
                }

                $contents[] = ['role' => 'model', 'parts' => $response->parts];
                $functionResponses = [];

                foreach ($calls as $call) {
                    $emit('tool', ['name' => $call['name'], 'label' => self::TOOL_LABELS[$call['name']] ?? 'Consultando dados']);

                    $toolStarted = microtime(true);
                    $result = $this->tools->execute($call['name'], $call['args'], $context);
                    $toolLog[] = [
                        'name' => $call['name'],
                        'label' => self::TOOL_LABELS[$call['name']] ?? $call['name'],
                        'args' => $call['args'],
                        'ms' => (int) round((microtime(true) - $toolStarted) * 1000),
                        'error' => $result->error,
                    ];
                    $toolData[] = $result->data;

                    if ($result->chart && ! empty($result->chart['labels'])) {
                        $charts[] = $result->chart;
                        $emit('chart', $result->chart);
                    }
                    if ($result->campaign) {
                        $campaign = $result->campaign;
                        $emit('campaign', $campaign);
                    }

                    $functionResponses[] = ['functionResponse' => [
                        'name' => $call['name'],
                        'response' => $result->data,
                    ]];
                }

                $contents[] = ['role' => 'user', 'parts' => $functionResponses];
            }
        } catch (Throwable $e) {
            return $this->fail($conversation, $e, $emit, $answer, $model, $startedAt);
        }

        $answer = trim($answer) ?: 'Não consegui gerar uma resposta para essa pergunta. Pode reformular?';
        $verification = $this->guard->check($answer, $toolData, $question);
        $latency = (int) round((microtime(true) - $startedAt) * 1000);

        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $answer,
            'meta' => array_filter([
                'tools' => $toolLog,
                'charts' => $charts,
                'campaign' => $campaign,
                'verification' => $verification,
            ]),
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => round($cost, 6),
            'latency_ms' => $latency,
        ]);
        $conversation->touch();

        Log::info('ai.answer', [
            'conversation' => $conversation->id,
            'model' => $model,
            'tools' => array_column($toolLog, 'name'),
            'prompt_tokens' => $promptTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => round($cost, 6),
            'latency_ms' => $latency,
            'numbers' => $verification['status'],
        ]);

        $emit('done', $this->present($message));

        return $message;
    }

    public function present(Message $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'status' => $message->status,
            'meta' => $message->meta ?? (object) [],
            'model' => $message->model,
            'tokens' => $message->prompt_tokens + $message->output_tokens,
            'cost_usd' => $message->cost_usd,
            'latency_ms' => $message->latency_ms,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * Tenta os modelos configurados em ordem. Só troca de modelo se a falha ocorrer
     * ANTES de qualquer texto ter sido enviado ao usuário (senão a resposta ficaria duplicada).
     */
    private function generate(array $payload, callable $onText): GeminiResponse
    {
        $models = config('ai.models');
        $streamed = false;
        $tracked = function (string $text) use (&$streamed, $onText) {
            $streamed = true;
            $onText($text);
        };

        foreach ($models as $index => $model) {
            try {
                return $this->gemini->stream($model, $payload, $tracked);
            } catch (AiProviderException $e) {
                $hasNext = $index < count($models) - 1;
                if (! $e->retryable || $streamed || ! $hasNext) {
                    throw $e;
                }
                Log::warning('ai.fallback', ['from' => $model, 'to' => $models[$index + 1], 'reason' => $e->getMessage()]);
            }
        }

        throw new AiProviderException('Nenhum modelo de IA configurado.');
    }

    private function payload(Conversation $conversation, CarbonImmutable $now, array $contents, bool $lastRound): array
    {
        return [
            'systemInstruction' => ['parts' => [['text' => SystemPrompt::build($conversation->store->name, $now)]]],
            'contents' => $contents,
            'tools' => [['functionDeclarations' => $this->tools->declarations()]],
            // Na última rodada a IA é obrigada a responder com o que já tem (evita loop de ferramentas)
            'toolConfig' => ['functionCallingConfig' => ['mode' => $lastRound ? 'NONE' : 'AUTO']],
            'generationConfig' => array_filter([
                'temperature' => config('ai.temperature'),
                'maxOutputTokens' => config('ai.max_output_tokens'),
                // Modelos com raciocínio: nível menor = respostas mais rápidas e baratas
                'thinkingConfig' => config('ai.thinking_level') ? ['thinkingLevel' => config('ai.thinking_level')] : null,
            ]),
        ];
    }

    /** Memória curta: só o texto das últimas mensagens (sem dados de ferramentas, para economizar tokens). */
    private function history(Conversation $conversation): array
    {
        $messages = $conversation->messages()
            ->reorder('id', 'desc')
            ->where('status', 'ok')
            ->limit(config('ai.history_messages'))
            ->get(['role', 'content'])
            ->reverse();

        // A conversa enviada ao modelo deve começar pelo usuário e alternar papéis
        $contents = [];
        foreach ($messages as $m) {
            $role = $m->role === 'assistant' ? 'model' : 'user';
            if ($contents === [] && $role === 'model') {
                continue;
            }
            $last = array_key_last($contents);
            if ($last !== null && $contents[$last]['role'] === $role) {
                $contents[$last]['parts'][] = ['text' => $m->content];

                continue;
            }
            $contents[] = ['role' => $role, 'parts' => [['text' => $m->content]]];
        }

        // Pergunta anterior que ficou sem resposta (erro) é descartada; a nova pergunta entra em seguida
        if ($contents !== [] && end($contents)['role'] === 'user') {
            array_pop($contents);
        }

        return $contents;
    }

    private function cost(GeminiResponse $response): float
    {
        $prices = config("ai.pricing.{$response->model}") ?? config('ai.pricing.default');

        return $response->promptTokens / 1_000_000 * $prices['input']
            + $response->outputTokens / 1_000_000 * $prices['output'];
    }

    private function fail(Conversation $conversation, Throwable $e, callable $emit, string $partial, ?string $model, float $startedAt): Message
    {
        report($e);

        $userMessage = match (true) {
            $e instanceof AiProviderException && $e->status === 429 => 'O serviço de IA está com muitas solicitações no momento. Aguarde alguns segundos e tente novamente.',
            $e instanceof AiProviderException && $e->retryable => 'O serviço de IA está indisponível no momento. Tente novamente em instantes.',
            $e instanceof AiProviderException && str_contains($e->getMessage(), 'bloqueada') => $e->getMessage(),
            default => 'Não foi possível processar sua pergunta agora. Tente novamente.',
        };

        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => trim($partial) !== '' ? trim($partial) : $userMessage,
            'status' => 'error',
            'meta' => ['error' => $userMessage],
            'model' => $model,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $emit('error', ['message' => $userMessage, 'id' => $message->id]);

        return $message;
    }
}
