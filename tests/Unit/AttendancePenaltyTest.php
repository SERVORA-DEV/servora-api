<?php

namespace Tests\Unit;

use App\Support\AttendancePenalty;
use PHPUnit\Framework\TestCase;

class AttendancePenaltyTest extends TestCase
{
    private function rate(string $from, bool $late, float $lateAmount, bool $absent, float $absentAmount, string $mode = 'day'): array
    {
        return ['from' => $from, 'late_enabled' => $late, 'late_amount' => $lateAmount, 'late_mode' => $mode, 'absent_enabled' => $absent, 'absent_amount' => $absentAmount];
    }

    public function test_lateness_by_the_hour_charges_the_minutes_they_were_late(): void
    {
        $history = [$this->rate('2000-01-01', true, 60, true, 200, 'hour')];
        $days = [
            ['date' => '2026-10-01', 'status' => 'Late', 'late_minutes' => 30],
            ['date' => '2026-10-02', 'status' => 'Late', 'late_minutes' => 95],
            ['date' => '2026-10-03', 'status' => 'Late', 'late_minutes' => 0],
            ['date' => '2026-10-04', 'status' => 'Absent'],
        ];

        $this->assertSame([
            ['date' => '2026-10-04', 'kind' => 'absent', 'amount' => 200.0, 'minutes' => null],
            ['date' => '2026-10-02', 'kind' => 'late', 'amount' => 95.0, 'minutes' => 95],
            ['date' => '2026-10-01', 'kind' => 'late', 'amount' => 30.0, 'minutes' => 30],
        ], AttendancePenalty::forDays($days, $history));
    }

    public function test_switching_from_per_day_to_hourly_only_reaches_later_days(): void
    {
        $history = [
            $this->rate('2000-01-01', true, 50, false, 0),
            $this->rate('2026-10-10', true, 120, false, 0, 'hour'),
        ];
        $days = [
            ['date' => '2026-10-09', 'status' => 'Late', 'late_minutes' => 45],
            ['date' => '2026-10-10', 'status' => 'Late', 'late_minutes' => 45],
        ];

        $this->assertSame([90.0, 50.0], array_column(AttendancePenalty::forDays($days, $history), 'amount'));
    }

    public function test_late_and_absent_days_are_charged_and_nothing_else(): void
    {
        $history = [$this->rate('2000-01-01', true, 50, true, 200)];
        $days = [
            ['date' => '2026-10-01', 'status' => 'Late'],
            ['date' => '2026-10-02', 'status' => 'Absent'],
            ['date' => '2026-10-03', 'status' => 'Present'],
            ['date' => '2026-10-04', 'status' => 'On Leave'],
            ['date' => '2026-10-05', 'status' => 'Holiday'],
            ['date' => '2026-10-06', 'status' => 'Fill In'],
            ['date' => '2026-10-07', 'status' => null],
        ];

        $this->assertSame([
            ['date' => '2026-10-02', 'kind' => 'absent', 'amount' => 200.0, 'minutes' => null],
            ['date' => '2026-10-01', 'kind' => 'late', 'amount' => 50.0, 'minutes' => 0],
        ], AttendancePenalty::forDays($days, $history));
    }

    public function test_a_day_keeps_the_rate_in_force_on_it(): void
    {
        $history = [
            $this->rate('2000-01-01', true, 50, false, 0),
            $this->rate('2026-10-10', true, 80, true, 300),
        ];
        $days = [
            ['date' => '2026-10-09', 'status' => 'Late'],
            ['date' => '2026-10-09', 'status' => 'Absent'],
            ['date' => '2026-10-10', 'status' => 'Late'],
            ['date' => '2026-10-11', 'status' => 'Absent'],
        ];

        $this->assertSame([300.0, 80.0, 50.0], array_column(AttendancePenalty::forDays($days, $history), 'amount'));
    }

    public function test_switched_off_or_zero_charges_nothing(): void
    {
        $days = [['date' => '2026-10-01', 'status' => 'Late'], ['date' => '2026-10-02', 'status' => 'Absent']];

        $this->assertSame([], AttendancePenalty::forDays($days, [$this->rate('2000-01-01', false, 50, false, 200)]));
        $this->assertSame([], AttendancePenalty::forDays($days, [$this->rate('2000-01-01', true, 0, true, 0)]));
    }

    public function test_earnings_never_go_below_zero(): void
    {
        $this->assertSame(['penalties' => 250.0, 'net' => 750.0, 'capped' => 0.0], AttendancePenalty::settle(1000, 250));
        $this->assertSame(['penalties' => 400.0, 'net' => 0.0, 'capped' => 100.0], AttendancePenalty::settle(300, 400));
        $this->assertSame(['penalties' => 200.0, 'net' => 0.0, 'capped' => 200.0], AttendancePenalty::settle(0, 200));
    }
}
