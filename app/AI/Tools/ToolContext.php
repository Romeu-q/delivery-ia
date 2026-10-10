<?php

namespace App\AI\Tools;

use Carbon\CarbonImmutable;

/**
 * Contexto confiável de execução das ferramentas. Vem do backend (sessão/loja),
 * NUNCA dos argumentos gerados pela IA — por isso o modelo não consegue consultar outra loja.
 */
final class ToolContext
{
    public function __construct(
        public readonly int $storeId,
        public readonly CarbonImmutable $now,
        public readonly ?string $conversationId = null,
    ) {}
}
