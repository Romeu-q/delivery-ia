<?php

namespace Tests\Unit;

use App\Analytics\PeriodResolver;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PeriodResolverTest extends TestCase
{
    private PeriodResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PeriodResolver(CarbonImmutable::parse('2026-03-15 20:00:00'));
    }

    public function test_this_month_is_compared_with_the_same_days_of_previous_month(): void
    {
        $period = $this->resolver->resolve('this_month');
        $previous = $period->previous();

        $this->assertSame('2026-03-01 00:00:00', $period->start->toDateTimeString());
        $this->assertSame('2026-03-15 20:00:00', $period->end->toDateTimeString());
        $this->assertSame('2026-02-01 00:00:00', $previous->start->toDateTimeString());
        $this->assertSame('2026-02-15 20:00:00', $previous->end->toDateTimeString());
    }

    public function test_last_month_previous_is_the_month_before(): void
    {
        $previous = $this->resolver->resolve('last_month')->previous();

        $this->assertSame('2026-01-01 00:00:00', $previous->start->toDateTimeString());
        $this->assertSame('2026-02-01 00:00:00', $previous->end->toDateTimeString());
    }

    public function test_rolling_periods_have_exact_length_and_contiguous_previous(): void
    {
        $period = $this->resolver->resolve('last_30_days');

        $this->assertSame(30, $period->days());
        $this->assertTrue($period->previous()->end->eq($period->start));
        $this->assertSame(30, $period->previous()->days());
    }

    public function test_custom_period_includes_the_end_date(): void
    {
        $period = $this->resolver->resolve('custom', '2026-01-10', '2026-01-20');

        $this->assertSame('2026-01-21 00:00:00', $period->end->toDateTimeString());
    }

    public function test_unknown_period_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolver->resolve('DROP TABLE orders');
    }
}
