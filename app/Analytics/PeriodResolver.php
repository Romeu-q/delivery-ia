<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Converte os períodos que a IA pode pedir (lista fechada) em intervalos concretos.
 * A IA nunca monta datas em SQL: ela escolhe uma chave e, no máximo, datas YYYY-MM-DD validadas.
 */
final class PeriodResolver
{
    public const KEYS = [
        'today', 'yesterday', 'this_week', 'last_week', 'last_7_days', 'last_30_days',
        'last_90_days', 'this_month', 'last_month', 'this_year', 'last_12_months', 'custom',
    ];

    public function __construct(private readonly CarbonImmutable $now) {}

    public function resolve(string $key, ?string $startDate = null, ?string $endDate = null): Period
    {
        $now = $this->now;
        $today = $now->startOfDay();
        // Períodos móveis terminam no fim do dia de hoje: têm duração exata e o anterior fica alinhado
        $tomorrow = $today->addDay();

        return match ($key) {
            'today' => new Period($today, $tomorrow, 'hoje'),
            'yesterday' => new Period($today->subDay(), $today, 'ontem'),
            'this_week' => new Period($now->startOfWeek(), $tomorrow, 'esta semana (até agora)'),
            'last_week' => new Period($now->startOfWeek()->subWeek(), $now->startOfWeek(), 'semana passada'),
            'last_7_days' => new Period($today->subDays(6), $tomorrow, 'últimos 7 dias'),
            'last_30_days' => new Period($today->subDays(29), $tomorrow, 'últimos 30 dias'),
            'last_90_days' => new Period($today->subDays(89), $tomorrow, 'últimos 90 dias'),
            'this_month' => new Period($now->startOfMonth(), $now, 'este mês (até agora)', Period::ALIGN_MONTH),
            'last_month' => new Period(
                $now->startOfMonth()->subMonthNoOverflow(),
                $now->startOfMonth(),
                'mês passado',
                Period::ALIGN_MONTH,
            ),
            'this_year' => new Period($now->startOfYear(), $now, 'este ano (até agora)', Period::ALIGN_YEAR),
            'last_12_months' => new Period($tomorrow->subMonthsNoOverflow(12), $tomorrow, 'últimos 12 meses'),
            'custom' => $this->custom($startDate, $endDate),
            default => throw new InvalidArgumentException("Período desconhecido: {$key}"),
        };
    }

    private function custom(?string $startDate, ?string $endDate): Period
    {
        if (! $startDate || ! $endDate) {
            throw new InvalidArgumentException('Período personalizado exige start_date e end_date.');
        }

        $start = CarbonImmutable::createFromFormat('!Y-m-d', $startDate);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $endDate)->addDay(); // fim inclusivo

        if ($end->lte($start)) {
            throw new InvalidArgumentException('A data final deve ser posterior à inicial.');
        }
        if ($start->diffInDays($end) > 800) {
            throw new InvalidArgumentException('Período máximo de 800 dias.');
        }

        return new Period($start, $end->gt($this->now) ? $this->now : $end,"de {$start->format('d/m/Y')} a {$end->subDay()->format('d/m/Y')}");
    }
}
