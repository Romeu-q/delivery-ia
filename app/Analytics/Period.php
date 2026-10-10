<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;

/**
 * Intervalo de datas [start, end) usado nas consultas, com a regra para obter
 * o período anterior equivalente (base das comparações automáticas).
 */
final class Period
{
    public const ALIGN_MONTH = 'month';

    public const ALIGN_YEAR = 'year';

    public const ALIGN_DURATION = 'duration';

    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $label,
        public readonly string $alignment = self::ALIGN_DURATION,
    ) {}

    /**
     * Período anterior comparável. "Este mês" (até hoje) compara com os mesmos dias do mês
     * anterior, e não com o mês anterior inteiro, para não distorcer a variação.
     */
    public function previous(): self
    {
        return match ($this->alignment) {
            self::ALIGN_MONTH => new self(
                $this->start->subMonthNoOverflow(),
                $this->end->subMonthNoOverflow(),
                'mesmo intervalo do mês anterior',
                self::ALIGN_MONTH,
            ),
            self::ALIGN_YEAR => new self(
                $this->start->subYearNoOverflow(),
                $this->end->subYearNoOverflow(),
                'mesmo intervalo do ano anterior',
                self::ALIGN_YEAR,
            ),
            default => new self(
                $this->start->subSeconds($this->lengthInSeconds()),
                $this->start,
                'período anterior de mesma duração',
            ),
        };
    }

    public function lengthInSeconds(): int
    {
        return (int) $this->start->diffInSeconds($this->end);
    }

    public function days(): int
    {
        return max(1, (int) ceil($this->lengthInSeconds() / 86400));
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'start' => $this->start->toDateTimeString(),
            'end' => $this->end->toDateTimeString(),
        ];
    }
}
