<?php

namespace App\AI\Tools;

use Illuminate\Validation\Rule;

class GetSalesTimeseries extends Tool
{
    public function name(): string
    {
        return 'get_sales_timeseries';
    }

    public function description(): string
    {
        return 'Evolução do faturamento, pedidos e ticket médio ao longo do tempo (por dia, semana ou mês). '
            .'Use para tendências, crescimento, sazonalidade e "como foram meus últimos meses". Gera um gráfico de linha.';
    }

    protected function properties(): array
    {
        return $this->periodProperties('last_12_months') + [
            'granularity' => ['type' => 'string', 'enum' => ['day', 'week', 'month'], 'description' => 'Agrupamento (padrão: month).'],
        ];
    }

    protected function rules(): array
    {
        return $this->periodRules() + ['granularity' => ['nullable', Rule::in(['day', 'week', 'month'])]];
    }

    protected function run(array $args, ToolContext $context): ToolResult
    {
        $period = $this->period($args, $context, 'last_12_months');
        $granularity = $args['granularity'] ?? 'month';
        $series = $this->analytics->timeseries($context->storeId, $period, $granularity);

        $labels = array_column($series, 'period');

        return new ToolResult(
            ['period' => $period->toArray(), 'granularity' => $granularity, 'series' => $series],
            $this->chart('line', 'Faturamento por '.['day' => 'dia', 'week' => 'semana', 'month' => 'mês'][$granularity], $labels, [
                ['label' => 'Faturamento', 'data' => array_column($series, 'revenue')],
            ]),
        );
    }
}
