<?php

namespace App\AI\Tools;

class GetInactiveCustomers extends Tool
{
    public function name(): string
    {
        return 'get_inactive_customers';
    }

    public function description(): string
    {
        return 'Clientes que não fazem pedidos há pelo menos N dias (opcionalmente no máximo M dias), com o total '
            .'encontrado, quanto eles já gastaram historicamente e os de maior valor. Use para "quais clientes não '
            .'compram há mais de 30 dias" e antes de sugerir campanhas de reativação.';
    }

    protected function properties(): array
    {
        return [
            'min_days_without_order' => ['type' => 'integer', 'description' => 'Mínimo de dias sem pedir (7 a 365, padrão 30).'],
            'max_days_without_order' => ['type' => 'integer', 'description' => 'Máximo de dias sem pedir (opcional), para focar em quem saiu recentemente.'],
            'limit' => ['type' => 'integer', 'description' => 'Quantos clientes listar (1 a 15, padrão 5).'],
        ];
    }

    protected function rules(): array
    {
        return [
            'min_days_without_order' => ['nullable', 'integer', 'min:7', 'max:365'],
            'max_days_without_order' => ['nullable', 'integer', 'max:730', 'gt:min_days_without_order'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:15'],
        ];
    }

    protected function run(array $args, ToolContext $context): ToolResult
    {
        return new ToolResult($this->analytics->inactiveCustomers(
            $context->storeId,
            $context->now,
            (int) ($args['min_days_without_order'] ?? 30),
            isset($args['max_days_without_order']) ? (int) $args['max_days_without_order'] : null,
            (int) ($args['limit'] ?? 5),
        ));
    }
}
