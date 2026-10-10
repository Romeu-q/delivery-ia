<?php

namespace App\AI\Gemini;

final class GeminiResponse
{
    public function __construct(
        public readonly string $model,
        /** Partes do conteúdo do modelo, como recebidas (necessário reenviar em chamadas de função). */
        public readonly array $parts,
        public readonly int $promptTokens,
        public readonly int $outputTokens,
        public readonly ?string $finishReason,
    ) {}

    /** @return array<int, array{name: string, args: array}> */
    public function functionCalls(): array
    {
        $calls = [];
        foreach ($this->parts as $part) {
            if (isset($part['functionCall']['name'])) {
                $calls[] = [
                    'name' => $part['functionCall']['name'],
                    'args' => (array) ($part['functionCall']['args'] ?? []),
                ];
            }
        }

        return $calls;
    }
}
