<?php

namespace Tests\Unit;

use App\Support\AttendanceKept;
use PHPUnit\Framework\TestCase;

// An attendance day stays as it turned out.
class AttendanceKeptTest extends TestCase
{
    private function live(array $over = []): array
    {
        return $over + [
            'status' => 'Present', 'late_minutes' => null, 'work_minutes' => null, 'overtime_minutes' => null,
            'scheduled_start' => '09:00', 'scheduled_end' => '18:00', 'is_day_off' => false,
            'missing_check_in' => false, 'missing_check_out' => false,
        ];
    }

    private function kept(array $over = []): array
    {
        return $over + [
            'status' => 'Late', 'late_minutes' => 12, 'work_minutes' => 500, 'overtime_minutes' => 20,
            'scheduled_start' => '09:00', 'scheduled_end' => '18:00', 'checked_in' => true, 'checked_out' => true,
        ];
    }

    public function test_a_day_with_nothing_kept_is_shown_as_worked_out_now(): void
    {
        $live = $this->live(['status' => 'Late', 'late_minutes' => 7]);

        $this->assertSame($live, AttendanceKept::apply($live, null));
    }

    public function test_a_checked_out_day_is_shown_exactly_as_it_was_kept(): void
    {
        // Today's settings would say: on time, shift 10–7, no overtime.
        $live = $this->live(['status' => 'Present', 'late_minutes' => null, 'work_minutes' => 480, 'overtime_minutes' => 0, 'scheduled_start' => '10:00', 'scheduled_end' => '19:00']);

        $shown = AttendanceKept::apply($live, $this->kept());

        $this->assertSame('Late', $shown['status']);
        $this->assertSame(12, $shown['late_minutes']);
        $this->assertSame(500, $shown['work_minutes']);
        $this->assertSame(20, $shown['overtime_minutes']);
        $this->assertSame('09:00', $shown['scheduled_start']);
        $this->assertSame('18:00', $shown['scheduled_end']);
        $this->assertFalse($shown['is_day_off']);
    }

    public function test_someone_still_on_the_floor_keeps_their_lateness_while_the_rest_stays_open(): void
    {
        // The late threshold was raised after they checked in 12 minutes late.
        $live = $this->live(['status' => 'Present', 'late_minutes' => null]);

        $shown = AttendanceKept::apply($live, $this->kept(['checked_out' => false, 'work_minutes' => null, 'overtime_minutes' => null]));

        $this->assertSame('Late', $shown['status']);
        $this->assertSame(12, $shown['late_minutes']);
        $this->assertNull($shown['work_minutes']);
    }

    public function test_a_day_never_checked_out_still_reads_incomplete_with_its_lateness(): void
    {
        $live = $this->live(['status' => 'Incomplete', 'missing_check_out' => true]);

        $shown = AttendanceKept::apply($live, $this->kept(['checked_out' => false, 'work_minutes' => null]));

        $this->assertSame('Incomplete', $shown['status']);
        $this->assertSame(12, $shown['late_minutes']);
        $this->assertTrue($shown['missing_check_out']);
    }

    public function test_a_fill_in_or_left_early_result_is_not_turned_into_late(): void
    {
        $this->assertSame('Fill In', AttendanceKept::toKeep($this->live(['status' => 'Fill In']))['status']);
        $this->assertSame('Left Early', AttendanceKept::toKeep($this->live(['status' => 'Left Early', 'late_minutes' => 9]))['status']);
    }

    public function test_the_check_in_fixes_the_shift_and_lateness(): void
    {
        $kept = AttendanceKept::toKeep($this->live(['status' => 'Late', 'late_minutes' => 12]));

        $this->assertSame(['status' => 'Late', 'late_minutes' => 12, 'work_minutes' => null, 'overtime_minutes' => null, 'scheduled_start' => '09:00', 'scheduled_end' => '18:00'], $kept);
    }

    public function test_the_check_out_adds_the_hours_without_touching_what_the_check_in_fixed(): void
    {
        // By check-out the threshold was raised (today: on time) and the shift moved.
        $live = $this->live(['status' => 'Present', 'late_minutes' => null, 'work_minutes' => 500, 'overtime_minutes' => 20, 'scheduled_start' => '10:00', 'scheduled_end' => '19:00']);

        $kept = AttendanceKept::toKeep($live, ['late_minutes' => 12, 'scheduled_start' => '09:00', 'scheduled_end' => '18:00']);

        $this->assertSame('Late', $kept['status']);
        $this->assertSame(12, $kept['late_minutes']);
        $this->assertSame(500, $kept['work_minutes']);
        $this->assertSame(20, $kept['overtime_minutes']);
        $this->assertSame('09:00', $kept['scheduled_start']);
    }

    public function test_a_day_on_leave_keeps_its_status_and_nothing_else(): void
    {
        $kept = AttendanceKept::toKeep($this->live(['status' => 'On Leave', 'scheduled_start' => null, 'scheduled_end' => null]));

        $this->assertSame('On Leave', $kept['status']);
        $this->assertNull($kept['late_minutes']);

        $live = $this->live(['status' => 'On Leave']);
        $this->assertSame($live, AttendanceKept::apply($live, ['checked_in' => false, 'checked_out' => false] + $kept));
    }
}
