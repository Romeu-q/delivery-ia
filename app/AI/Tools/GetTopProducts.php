<?php

namespace App\AI\Tools;

use Illuminate\Validation\Rule;

class GetTopProducts extends Tool
{
    public function name(): string
    {
        return 'get_top_products';
    }

    public function description(): string
    {
        return 'Ranking dos produtos mais vendidos no período, por faturamento ou quantidade, opcionalmente filtrado '
            .'por categoria. Use para "qual produto mais vendeu" e "carro-chefe".';
    }

    protected function properties(): array
    {
        return $this->periodProperties() + [
            'sort_by' => ['type' => 'string', 'enum' => ['revenue', 'quantity'], 'description' => 'Ordenação (padrão: revenue).'],
            'limit' => ['type' => 'integer', 'description' => 'Quantidade de produtos (1 a 15, padrão 5).'],
            'category' => ['type' => 'string', 'description' => 'Filtrar por categoria exata (opcional).'],
        ];
    }

    protected function rules(): array
    {
        return $this->periodRules() + [
            'sort_by' => ['nullable', Rule::in(['revenue', 'quantity'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:15'],
            'category' => ['nullable', 'string', 'max:60'],
        ];
    }

    protected function run(array $args, ToolContext $context): ToolResult
    {
        $period = $this->period($args, $context);
        $sortBy = $args['sort_by'] ?? 'revenue';
        $rows = $this->analytics->topProducts($context->storeId, $period, (int) ($args['limit'] ?? 5), $sortBy, $args['category'] ?? null);

        return new ToolResult(
            ['period' => $period->toArray(), 'sorted_by' => $sortBy, 'products' => $rows],
            $this->chart(
                'bar',
                $sortBy === 'quantity' ? 'Produtos mais vendidos (unidades)' : 'Produtos mais vendidos (faturamento)',
                array_column($rows, 'product'),
                [['label' => $sortBy === 'quantity' ? 'Unidades' : 'Faturamento', 'data' => array_column($rows, $sortBy)]],
                $sortBy === 'quantity' ? 'number' : 'currency',
            ),
        );
    }
}
