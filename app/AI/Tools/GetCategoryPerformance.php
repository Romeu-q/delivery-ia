<?php

namespace App\AI\Tools;

class GetCategoryPerformance extends Tool
{
    public function name(): string
    {
        return 'get_category_performance';
    }

    public function description(): string
    {
        return 'Faturamento e quantidade por categoria de produto, comparados com o período anterior, e a participação '
            .'de cada categoria no faturamento. Use para entender quais categorias puxaram uma queda ou crescimento.';
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
        $rows = $this->analytics->categoryPerformance($context->storeId, $period);

        return new ToolResult(
            ['period' => $period->toArray(), 'compared_with' => $period->previous()->toArray(), 'categories' => $rows],
            $this->chart('bar', 'Faturamento por categoria', array_column($rows, 'category'), [
                ['label' => 'Período anterior', 'data' => array_column($rows, 'revenue_previous')],
                ['label' => 'Período atual', 'data' => array_column($rows, 'revenue_current')],
            ]),
        );
    }
}
