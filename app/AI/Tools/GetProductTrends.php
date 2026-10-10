<?php

namespace App\AI\Tools;

use Illuminate\Validation\Rule;

class GetProductTrends extends Tool
{
    public function name(): string
    {
        return 'get_product_trends';
    }

    public function description(): string
    {
        return 'Produtos que mais PERDERAM (declining) ou GANHARAM (growing) vendas em relação ao período anterior '
            .'equivalente, com quantidades e variações já calculadas. Use para "quais produtos estão perdendo vendas".';
    }

    protected function properties(): array
    {
        return $this->periodProperties() + [
            'direction' => ['type' => 'string', 'enum' => ['declining', 'growing'], 'description' => 'Padrão: declining.'],
            'metric' => ['type' => 'string', 'enum' => ['quantity', 'revenue'], 'description' => 'Ordenar pela variação de unidades ou de faturamento (padrão: quantity).'],
            'limit' => ['type' => 'integer', 'description' => '1 a 10, padrão 5.'],
        ];
    }

    protected function rules(): array
    {
        return $this->periodRules() + [
            'direction' => ['nullable', Rule::in(['declining', 'growing'])],
            'metric' => ['nullable', Rule::in(['quantity', 'revenue'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }

    protected function run(array $args, ToolContext $context): ToolResult
    {
        $period = $this->period($args, $context);
        $direction = $args['direction'] ?? 'declining';
        $metric = $args['metric'] ?? 'quantity';
        $rows = $this->analytics->productTrends($context->storeId, $period, $direction, (int) ($args['limit'] ?? 5), $metric);
        $byRevenue = $metric === 'revenue';

        return new ToolResult(
            [
                'period' => $period->toArray(),
                'compared_with' => $period->previous()->toArray(),
                'direction' => $direction,
                'sorted_by' => "{$metric}_change",
                'products' => $rows,
            ],
            $rows ? $this->chart(
                'bar',
                ($direction === 'growing' ? 'Produtos em alta' : 'Produtos em queda').($byRevenue ? ' (faturamento)' : ' (unidades)'),
                array_column($rows, 'product'),
                [
                    ['label' => 'Período anterior', 'data' => array_column($rows, $byRevenue ? 'revenue_previous' : 'quantity_previous')],
                    ['label' => 'Período atual', 'data' => array_column($rows, $byRevenue ? 'revenue_current' : 'quantity_current')],
                ],
                $byRevenue ? 'currency' : 'number',
            ) : null,
        );
    }
}
