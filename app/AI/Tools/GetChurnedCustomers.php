<?php

namespace App\AI\Tools;

class GetChurnedCustomers extends Tool
{
    public function name(): string
    {
        return 'get_churned_customers';
    }

    public function description(): string
    {
        return 'Clientes que compraram no período anterior e ainda NÃO compraram no período atual (deixando de comprar), '
            .'com quantidade, percentual e faturamento que eles geravam. Use para "quais clientes estão deixando de comprar" '
            .'e para explicar quedas de vendas.';
    }

    protected function properties(): array
    {
        return $this->periodProperties('last_30_days') + [
            'limit' => ['type' => 'integer', 'description' => 'Quantos clientes listar (1 a 15, padrão 5).'],
        ];
    }

    protected function rules(): array
    {
        return $this->periodRules() + ['limit' => ['nullable', 'integer', 'min:1', 'max:15']];
    }

    protected function run(array $args, ToolContext $context): ToolResult
    {
        $period = $this->period($args, $context, 'last_30_days');

        return new ToolResult(['period' => $period->toArray()]
            + $this->analytics->churnedCustomers($context->storeId, $period, (int) ($args['limit'] ?? 5)));
    }
}
