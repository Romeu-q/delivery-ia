<?php

namespace App\AI\Tools;

class GetSalesByHour extends Tool
{
    public function name(): string
    {
        return 'get_sales_by_hour';
    }

    public function description(): string
    {
        return 'Quantidade de pedidos e faturamento por hora do dia. Use para "qual horário tem mais pedidos", '
            .'planejamento de equipe e horários de promoção.';
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
        $rows = $this->analytics->byHour($context->storeId, $period);

        return new ToolResult(
            ['period' => $period->toArray(), 'by_hour' => $rows],
            $this->chart('bar', 'Pedidos por horário', array_column($rows, 'hour'), [
                ['label' => 'Pedidos', 'data' => array_column($rows, 'orders')],
            ], 'number'),
        );
    }
}
