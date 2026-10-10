<?php

namespace App\AI\Gemini;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP mínimo da API do Gemini (streamGenerateContent via SSE).
 * Não usa SDK: o fluxo fica explícito e fácil de trocar de provedor.
 */
class GeminiClient
{
    private const RETRYABLE_STATUS = [408, 429, 500, 502, 503, 504];

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    /**
     * Envia a requisição e repassa cada trecho de texto para $onText assim que chega.
     *
     * @param  callable(string): void  $onText
     */
    public function stream(string $model, array $payload, callable $onText): GeminiResponse
    {
        if (! $this->apiKey) {
            throw new AiProviderException('GEMINI_API_KEY não configurada.');
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->connectTimeout(10)
                ->timeout($this->timeout)
                ->withOptions(['stream' => true])
                ->post("{$this->baseUrl}/models/{$model}:streamGenerateContent?alt=sse", $payload);
        } catch (ConnectionException $e) {
            throw new AiProviderException('Falha de conexão com o provedor de IA.', retryable: true, previous: $e);
        }

        if ($response->failed()) {
            $status = $response->status();
            $detail = $response->json('error.message') ?? substr((string) $response->body(), 0, 300);

            throw new AiProviderException(
                "Gemini respondeu HTTP {$status}: {$detail}",
                $status,
                in_array($status, self::RETRYABLE_STATUS, true),
            );
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $parts = [];
        $usage = [];
        $finishReason = null;

        $handleLine = function (string $line) use (&$parts, &$usage, &$finishReason, $onText) {
            $line = trim($line);
            if (! str_starts_with($line, 'data:')) {
                return;
            }
            $chunk = json_decode(trim(substr($line, 5)), true);
            if (! is_array($chunk)) {
                return;
            }

            if (isset($chunk['promptFeedback']['blockReason'])) {
                throw new AiProviderException('A pergunta foi bloqueada pelos filtros de segurança do provedor.');
            }

            $candidate = $chunk['candidates'][0] ?? [];
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                // json_decode transforma {} em []; ao reenviar, args precisa continuar sendo objeto
                if (isset($part['functionCall']) && empty($part['functionCall']['args'])) {
                    $part['functionCall']['args'] = new \stdClass;
                }
                if (isset($part['text']) && empty($part['thought']) && $part['text'] !== '') {
                    $onText($part['text']);
                }
                $parts[] = $part;
            }
            $finishReason = $candidate['finishReason'] ?? $finishReason;
            $usage = $chunk['usageMetadata'] ?? $usage;
        };

        try {
            while (! $body->eof()) {
                $buffer .= $body->read(8192);
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $handleLine(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                }
            }
            $handleLine($buffer);
        } catch (AiProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AiProviderException('A conexão com o provedor de IA foi interrompida.', retryable: true, previous: $e);
        }

        return new GeminiResponse(
            $model,
            self::mergeTextParts($parts),
            (int) ($usage['promptTokenCount'] ?? 0),
            (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            $finishReason,
        );
    }

    /**
     * No streaming o texto chega fragmentado em várias partes; junta fragmentos de texto
     * consecutivos e preserva o resto (functionCall, thoughtSignature) exatamente como veio.
     */
    public static function mergeTextParts(array $parts): array
    {
        $merged = [];
        foreach ($parts as $part) {
            $last = array_key_last($merged);
            $isPlainText = array_keys($part) === ['text'];

            if ($isPlainText && $last !== null && array_keys($merged[$last]) === ['text']) {
                $merged[$last]['text'] .= $part['text'];

                continue;
            }
            $merged[] = $part;
        }

        return $merged;
    }
}
