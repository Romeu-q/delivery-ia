<?php

namespace Tests\Unit;

use App\AI\NumberGuard;
use PHPUnit\Framework\TestCase;

class NumberGuardTest extends TestCase
{
    private array $toolData = [[
        'metrics' => [
            'revenue' => ['current' => 2511.87, 'previous' => 3282.85, 'change' => -770.98, 'change_pct' => -23.5],
            'orders' => ['current' => 39, 'previous' => 48, 'change' => -9, 'change_pct' => -18.8],
            'avg_ticket' => ['current' => 64.41, 'previous' => 68.39],
        ],
        'by_hour' => [['hour' => '20h', 'orders' => 120]],
    ]];

    public function test_extracts_brazilian_number_formats(): void
    {
        $numbers = array_column(NumberGuard::extract('R$ 1.234,56, 14,2% e 37 clientes; 1234.5'), 1);

        $this->assertSame([1234.56, 14.2, 37.0, 1234.5], $numbers);
    }

    public function test_answer_with_numbers_from_the_database_is_verified(): void
    {
        $answer = 'Seu faturamento caiu 23,5%, de R$ 3.282,85 para R$ 2.511,87 (R$ 770,98 a menos). '
            .'Foram 39 pedidos contra 48 e o ticket médio passou de R$ 68,39 para R$ 64,41. O pico é às 20h.';

        $result = (new NumberGuard)->check($answer, $this->toolData);

        $this->assertSame('verified', $result['status']);
        $this->assertSame([], $result['unverified']);
    }

    public function test_invented_numbers_are_flagged(): void
    {
        $result = (new NumberGuard)->check('O faturamento caiu 31,7% e você perdeu 52 clientes.', $this->toolData);

        $this->assertSame('unverified', $result['status']);
        $this->assertSame(['31,7', '52'], $result['unverified']);
    }

    public function test_rounded_values_are_accepted(): void
    {
        $result = (new NumberGuard)->check('Queda de cerca de 24% (R$ 2.512 no mês).', $this->toolData);

        $this->assertSame('verified', $result['status']);
    }

    public function test_dates_years_list_markers_and_small_counts_are_ignored(): void
    {
        $answer = "Três ações para 2026:\n1. Campanha até 15/10\n2. Combo às 19:30\n3. Revisar 2 produtos";

        $result = (new NumberGuard)->check($answer, $this->toolData);

        $this->assertSame('no_numbers', $result['status']);
    }

    public function test_numbers_from_the_question_are_accepted(): void
    {
        $result = (new NumberGuard)->check('Encontrei os clientes sem pedir há mais de 45 dias.', [], 'clientes há mais de 45 dias');

        $this->assertSame('verified', $result['status']);
    }
}
