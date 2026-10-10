<?php

namespace App\AI\Tools;

class DetectAnomalies extends Tool
{
    public function name(): string
    {
        return 'detect_anomalies';
    }

    public function description(): string
    {
        return 'Identifica dias recentes com faturamento muito acima ou abaixo do esperado, comparando cada dia com a '
            .'média do mesmo dia da semana nas 8 semanas anteriores. Use para "aconteceu algo fora do normal?".';
    }

    protected function properties(): array
    {
        return [
            'days' => ['type' => 'integer', 'description' => 'Quantos dias recentes analisar (7 a 60, padrão 21).'],
        ];
    }

    protected function rules(): array
    {
        return ['days' => ['nullable', 'integer', 'min:7', 'max:60']];
    }

    protected function run(array $args, ToolContext $context): ToolResult
    {
        $days = (int) ($args['days'] ?? 21);

        return new ToolResult([
            'days_analyzed' => $days,
            'method' => 'z-score >= 2 vs média do mesmo dia da semana nas 8 semanas anteriores',
            'anomalies' => $this->analytics->dailyAnomalies($context->storeId, $context->now, $days),
        ]);
    }
}
