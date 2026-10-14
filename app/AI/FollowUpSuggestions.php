<?php

namespace App\AI;

/**
 * Sugestões de próxima pergunta, escolhidas pelo que acabou de ser consultado.
 * Regras fixas no backend: não custam tokens e sempre levam a perguntas que o sistema sabe responder.
 */
class FollowUpSuggestions
{
    private const BY_TOOL = [
        'get_sales_summary' => [
            'Quais categorias puxaram essa variação?',
            'Quais clientes deixaram de comprar?',
            'Como foi a evolução do faturamento nos últimos 12 meses?',
        ],
        'get_category_performance' => [
            'Quais produtos estão perdendo vendas?',
            'Quais produtos estão crescendo?',
        ],
        'get_product_trends' => [
            'Quais produtos estão crescendo?',
            'Qual produto mais vendeu este mês?',
            'Me dê três ações para recuperar as vendas desses produtos.',
        ],
        'get_top_products' => [
            'Quais produtos estão perdendo vendas?',
            'Como está o desempenho de cada categoria?',
        ],
        'get_sales_by_weekday' => [
            'Qual horário possui maior volume de pedidos?',
            'Me dê ações para vender mais nos dias mais fracos.',
        ],
        'get_sales_by_hour' => [
            'Qual foi meu melhor dia da semana?',
            'Me dê ações para aproveitar melhor os horários de pico.',
        ],
        'get_top_customers' => [
            'Quais clientes estão deixando de comprar?',
            'Quais clientes não compram há mais de 30 dias?',
        ],
        'get_inactive_customers' => [
            'Crie uma campanha para clientes que não compram há mais de 30 dias.',
            'Quais são meus melhores clientes?',
        ],
        'get_churned_customers' => [
            'Crie uma campanha para clientes que não compram há mais de 30 dias.',
            'Quais são meus melhores clientes?',
        ],
        'detect_anomalies' => [
            'Quanto vendi este mês?',
            'Por que minhas vendas caíram?',
        ],
        'get_sales_timeseries' => [
            'Por que minhas vendas caíram?',
            'Qual foi meu melhor dia da semana?',
        ],
        'create_campaign_draft' => [
            'Quais são meus melhores clientes?',
            'Me dê três ações para aumentar minhas vendas esta semana.',
        ],
    ];

    private const DEFAULT = [
        'Quanto vendi este mês?',
        'Por que minhas vendas caíram?',
        'Quais produtos estão perdendo vendas?',
    ];

    /** @param  string[]  $tools  ferramentas usadas na resposta, em ordem */
    public static function for(array $tools, string $question, int $limit = 3): array
    {
        $candidates = [];
        foreach (array_unique($tools) as $tool) {
            array_push($candidates, ...(self::BY_TOOL[$tool] ?? []));
        }
        array_push($candidates, ...self::DEFAULT);

        $asked = mb_strtolower(trim($question));

        return array_slice(array_values(array_unique(array_filter(
            $candidates,
            fn (string $s) => mb_strtolower($s) !== $asked,
        ))), 0, $limit);
    }
}
