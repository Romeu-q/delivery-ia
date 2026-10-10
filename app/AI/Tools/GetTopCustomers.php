<?php

namespace App\AI\Tools;

use Illuminate\Validation\Rule;

class GetTopCustomers extends Tool
{
    public function name(): string
    {
        return 'get_top_customers';
    }

    public function description(): string
    {
        return 'Melhores clientes do período por valor gasto ou número de pedidos (telefone vem mascarado). '
            .'Use para "quais são meus melhores clientes".';
    }

    protected function properties(): array
    {
        return $this->periodProperties('last_90_days') + [
            'sort_by' => ['type' => 'string', 'enum' => ['revenue', 'orders'], 'description' => 'Padrão: revenue.'],
            'limit' => ['type' => 'integer', 'description' => '1 a 15, padrão 5.'],
        ];
    }

    protected function rules(): array
    {
        return $this->periodRules() + [
            'sort_by' => ['nullable', Rule::in(['revenue', 'orders'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:15'],
        ];
    }

    protected function run(array $args, ToolContext $context): ToolResult
    {
        $period = $this->period($args, $context, 'last_90_days');
        $rows = $this->analytics->topCustomers($context->storeId, $period, (int) ($args['limit'] ?? 5), $args['sort_by'] ?? 'revenue');

        return new ToolResult(['period' => $period->toArray(), 'customers' => $rows]);
    }
}
