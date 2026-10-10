<?php

namespace App\AI\Tools;

/**
 * Resultado de uma ferramenta.
 *  - data: o que vai para a IA (já agregado e com variações calculadas);
 *  - chart: gráfico montado direto dos dados do banco e enviado ao front (não passa pela IA);
 *  - campaign: rascunho de campanha criado, exibido como cartão na interface.
 */
final class ToolResult
{
    public function __construct(
        public readonly array $data,
        public readonly ?array $chart = null,
        public readonly ?array $campaign = null,
        public readonly bool $error = false,
    ) {}

    public static function error(string $message): self
    {
        return new self(['error' => $message], error: true);
    }

    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'chart' => $this->chart,
            'campaign' => $this->campaign,
            'error' => $this->error,
        ];
    }

    public static function fromArray(array $result): self
    {
        return new self($result['data'], $result['chart'], $result['campaign'], $result['error']);
    }
}
