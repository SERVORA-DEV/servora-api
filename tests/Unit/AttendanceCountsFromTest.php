<?php

namespace Tests\Unit;

use App\Models\Attendance;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Service\Business\AttendanceStatusCalculator;
use Carbon\Carbon;
use Tests\TestCase;

// Nobody is absent from a shift that began before they were added to
// Servora. Counted from when their record was added (created_at), not the
// hire date on it: a spa that starts using Servora enters staff hired years
// ago, and none of them missed the months before.
//
// No database: the calculator only reads the models it is handed.
//   php artisan test --filter=AttendanceCountsFromTest
class AttendanceCountsFromTest extends TestCase
{
    // Added on Saturday 3 October 2026 at 10:51 PM, with a 2024 hire date.
    private function staff(): Staff
    {
        $staff = new Staff(['hire_date' => '2024-03-15']);
        $staff->created_at = Carbon::parse('2026-10-03 22:51:00');

        return $staff;
    }

    // Works 10 AM – 7 PM every day.
    private function resolve(string $date, ?Attendance $attendance = null, ?Staff $staff = null): array
    {
        $shift = new StaffSchedule(['day_of_week' => Carbon::parse($date)->format('l'), 'start_time' => '10:00', 'end_time' => '19:00', 'is_day_off' => false]);

        return (new AttendanceStatusCalculator)->resolve(
            $staff ?? $this->staff(),
            $attendance,
            $date,
            collect([$shift]),
            null,
            Carbon::parse('2026-10-07 14:00:00'),
        );
    }

    public function test_a_shift_from_before_they_were_added_is_not_an_absence(): void
    {
        $result = $this->resolve('2026-09-15');

        $this->assertNull($result['status']);
        $this->assertFalse($result['missing_check_in']);
    }

    // Their shift that day started at 10 AM; they were added at 10:51 PM.
    public function test_the_day_they_were_added_does_not_count_once_its_shift_had_started(): void
    {
        $this->assertNull($this->resolve('2026-10-03')['status']);
    }

    public function test_a_missed_shift_after_they_were_added_is_an_absence(): void
    {
        $result = $this->resolve('2026-10-04');

        $this->assertSame('Absent', $result['status']);
        $this->assertTrue($result['missing_check_in']);
    }

    // What was actually recorded for an earlier day still stands.
    public function test_a_record_made_for_an_earlier_day_still_counts(): void
    {
        $present = new Attendance(['attendance_date' => '2026-09-15', 'status' => 'Present']);
        $present->check_in_at = Carbon::parse('2026-09-15 10:02:00');
        $present->check_out_at = Carbon::parse('2026-09-15 19:00:00');
        $this->assertSame('Present', $this->resolve('2026-09-15', $present)['status']);

        $absent = new Attendance(['attendance_date' => '2026-09-16', 'status' => 'Absent']);
        $this->assertSame('Absent', $this->resolve('2026-09-16', $absent)['status']);
    }

    // Today's shift has not ended: still nothing to report either way.
    public function test_a_shift_still_running_is_not_an_absence(): void
    {
        $this->assertNull($this->resolve('2026-10-07')['status']);
    }
}
