<?php

namespace App\AI\Tools;

class GetSalesSummary extends Tool
{
    public function name(): string
    {
        return 'get_sales_summary';
    }

    public function description(): string
    {
        return 'Resumo de vendas de um período, já comparado com o período anterior equivalente: faturamento, '
            .'quantidade de pedidos, ticket médio, itens vendidos, descontos, taxas de entrega, clientes únicos, '
            .'clientes novos e recorrentes, cancelamentos e média de pedidos por dia. Cada métrica traz '
            .'current, previous, change e change_pct (variação % já calculada). Use para perguntas como '
            .'"quanto vendi", "faturamento aumentou ou diminuiu", "ticket médio", "por que as vendas caíram".';
    }

    protected function properties(): array
    {
        return $this->periodProperties();
    }

    protected function rules(): array
    {
        return $this->periodRules();
    }

    protected function run(array $args, ToolContext $context): ToolResult
    {
        $period = $this->period($args, $context);
        $previous = $period->previous();

        $current = $this->analytics->summary($context->storeId, $period);
        $before = $this->analytics->summary($context->storeId, $previous);

        return new ToolResult([
            'period' => $period->toArray(),
            'compared_with' => $previous->toArray(),
            'metrics' => $this->analytics->compare($current, $before),
            'definitions' => 'revenue = soma do total pago (subtotal - desconto + taxa) dos pedidos não cancelados; '
                .'avg_ticket = revenue / orders; change_pct = variação percentual vs período anterior.',
        ]);
    }
}
