<?php

namespace Tests\Unit;

use App\Support\StaleVisit;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class StaleVisitTest extends TestCase
{
    public function test_until_two_in_the_morning_yesterday_is_still_the_business_day(): void
    {
        $this->assertSame('2026-10-20', StaleVisit::today(Carbon::parse('2026-10-21 00:10')));
        $this->assertSame('2026-10-20', StaleVisit::today(Carbon::parse('2026-10-21 01:59')));
        $this->assertSame('2026-10-21', StaleVisit::today(Carbon::parse('2026-10-21 02:00')));
        $this->assertSame('2026-10-21', StaleVisit::today(Carbon::parse('2026-10-21 18:30')));
    }

    public function test_a_visit_is_stale_only_once_its_day_is_over(): void
    {
        // A service running past midnight is not cut off at 00:30.
        $this->assertFalse(StaleVisit::isStale('2026-10-20', Carbon::parse('2026-10-21 00:30')));
        $this->assertTrue(StaleVisit::isStale('2026-10-20', Carbon::parse('2026-10-21 02:00')));
        $this->assertTrue(StaleVisit::isStale('2026-10-19', Carbon::parse('2026-10-21 00:30')));
        // Today's and future visits never are.
        $this->assertFalse(StaleVisit::isStale('2026-10-21', Carbon::parse('2026-10-21 12:00')));
        $this->assertFalse(StaleVisit::isStale('2026-10-25', Carbon::parse('2026-10-21 12:00')));
    }

    public function test_it_reads_a_date_time_or_a_date_string(): void
    {
        $now = Carbon::parse('2026-10-22 09:00');
        $this->assertTrue(StaleVisit::isStale(Carbon::parse('2026-10-20 15:00'), $now));
        $this->assertTrue(StaleVisit::isStale('2026-10-20 15:00:00', $now));
    }
}
