<?php

namespace Tests\Unit;

use App\AI\FollowUpSuggestions;
use PHPUnit\Framework\TestCase;

class FollowUpSuggestionsTest extends TestCase
{
    public function test_suggestions_follow_the_tools_used(): void
    {
        $suggestions = FollowUpSuggestions::for(['get_inactive_customers'], 'Quais clientes sumiram?');

        $this->assertSame('Crie uma campanha para clientes que não compram há mais de 30 dias.', $suggestions[0]);
        $this->assertCount(3, $suggestions);
    }

    public function test_never_suggests_the_question_just_asked_nor_duplicates(): void
    {
        $suggestions = FollowUpSuggestions::for(
            ['detect_anomalies', 'get_sales_timeseries'],
            'por que minhas vendas caíram?',
        );

        $this->assertNotContains('Por que minhas vendas caíram?', $suggestions);
        $this->assertSame($suggestions, array_values(array_unique($suggestions)));
    }

    public function test_falls_back_to_general_questions_without_tools(): void
    {
        $this->assertSame(
            ['Quanto vendi este mês?', 'Por que minhas vendas caíram?', 'Quais produtos estão perdendo vendas?'],
            FollowUpSuggestions::for([], 'Oi'),
        );
    }
}
