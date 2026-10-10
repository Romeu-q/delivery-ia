<?php

namespace App\AI\Tools;

class GetSalesByWeekday extends Tool
{
    public function name(): string
    {
        return 'get_sales_by_weekday';
    }

    public function description(): string
    {
        return 'Pedidos e faturamento por dia da semana, incluindo médias por dia (justas mesmo quando o período '
            .'tem quantidades diferentes de cada dia). Use para "qual meu melhor/pior dia da semana".';
    }

    protected function properties(): array
    {
        return $this->periodProperties('last_90_days');
    }

    protected function rules(): array
    {
        return $this->periodRules();
    }

    protected function run(array $args, ToolContext $context): ToolResult
    {
        $period = $this->period($args, $context, 'last_90_days');
        $rows = $this->analytics->byWeekday($context->storeId, $period);

        return new ToolResult(
            ['period' => $period->toArray(), 'by_weekday' => $rows],
            $this->chart('bar', 'Faturamento médio por dia da semana', array_column($rows, 'weekday'), [
                ['label' => 'Faturamento médio/dia', 'data' => array_column($rows, 'avg_revenue_per_day')],
            ]),
        );
    }
}
