<?php

namespace Tests\Unit;

use App\Service\Business\CommissionCalculator;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

// The pay period the running commission total is shown for, by how often staff are paid.
class CommissionPayPeriodTest extends TestCase
{
    private function start(string $cycle, string $now): string
    {
        return (new CommissionCalculator())->periodStart(['commission_release_cycle' => $cycle], Carbon::parse($now))->toDateTimeString();
    }

    public function test_every_15_days_runs_the_1st_to_the_15th_and_the_16th_to_the_month_end(): void
    {
        $this->assertSame('2026-11-01 00:00:00', $this->start('semimonthly', '2026-11-01 08:00:00'));
        $this->assertSame('2026-11-01 00:00:00', $this->start('semimonthly', '2026-11-15 23:59:00'));
        $this->assertSame('2026-11-16 00:00:00', $this->start('semimonthly', '2026-11-16 00:01:00'));
        $this->assertSame('2026-11-16 00:00:00', $this->start('semimonthly', '2026-11-30 12:00:00'));
        $this->assertSame('2026-02-16 00:00:00', $this->start('semimonthly', '2026-02-28 12:00:00'));
    }

    public function test_the_other_cycles_are_unchanged(): void
    {
        $this->assertSame('2026-11-12 00:00:00', $this->start('daily', '2026-11-12 15:00:00'));
        $this->assertSame('2026-11-09 00:00:00', $this->start('weekly', '2026-11-12 15:00:00'));
        $this->assertSame('2026-11-01 00:00:00', $this->start('monthly', '2026-11-12 15:00:00'));
    }

    public function test_the_label_says_pay_period(): void
    {
        $this->assertSame('this pay period', (new CommissionCalculator())->periodLabel(['commission_release_cycle' => 'semimonthly']));
    }
}
