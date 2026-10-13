<?php

namespace Tests\Unit;

use App\Models\Attendance;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Service\Business\AttendanceStatusCalculator;
use Carbon\Carbon;
use Tests\TestCase;

// The Overtime policy (Settings → Staff Policies): off, worked hours stop at
// the end of the shift; on, time past the shift end counts and is reported
// separately.
//
// No database: the calculator only reads the models it is handed.
//   php artisan test --filter=AttendanceOvertimeTest
class AttendanceOvertimeTest extends TestCase
{
    private const DATE = '2026-10-05'; // a Monday

    private function resolve(?array $shift, string $in, string $out, bool $allowOvertime): array
    {
        $schedules = $shift
            ? collect([new StaffSchedule(['day_of_week' => 'Monday', 'start_time' => $shift[0], 'end_time' => $shift[1], 'is_day_off' => false])])
            : collect();

        $attendance = new Attendance([
            'attendance_date' => self::DATE,
            'check_in_at' => Carbon::parse($in),
            'check_out_at' => Carbon::parse($out),
            'status' => 'Present',
        ]);

        return (new AttendanceStatusCalculator)->resolve(
            new Staff,
            $attendance,
            self::DATE,
            $schedules,
            null,
            Carbon::parse(self::DATE . ' 23:00'),
            allowOvertime: $allowOvertime,
        );
    }

    public function test_with_overtime_off_hours_stop_at_the_shift_end(): void
    {
        // 9–6 shift, worked 9:00 to 8:00 PM.
        $result = $this->resolve(['09:00', '18:00'], self::DATE . ' 09:00', self::DATE . ' 20:00', false);

        $this->assertSame(9 * 60, $result['work_minutes']);
        $this->assertSame(0, $result['overtime_minutes']);
    }

    public function test_with_overtime_on_the_extra_time_counts_and_is_reported(): void
    {
        $result = $this->resolve(['09:00', '18:00'], self::DATE . ' 09:00', self::DATE . ' 20:00', true);

        $this->assertSame(11 * 60, $result['work_minutes']);
        $this->assertSame(2 * 60, $result['overtime_minutes']);
    }

    public function test_leaving_on_time_is_never_overtime(): void
    {
        $result = $this->resolve(['09:00', '18:00'], self::DATE . ' 09:00', self::DATE . ' 18:00', true);

        $this->assertSame(9 * 60, $result['work_minutes']);
        $this->assertSame(0, $result['overtime_minutes']);
    }

    public function test_without_a_shift_end_everything_counts_and_nothing_is_overtime(): void
    {
        // No schedule at all (a fill-in): there is no shift end to cross.
        $result = $this->resolve(null, self::DATE . ' 09:00', self::DATE . ' 20:00', false);

        $this->assertSame(11 * 60, $result['work_minutes']);
        $this->assertSame(0, $result['overtime_minutes']);
    }

    public function test_an_overnight_shift_ends_the_next_day(): void
    {
        // 10 PM – 2 AM shift, worked 10 PM to 3 AM: one hour past the end.
        $result = $this->resolve(['22:00', '02:00'], self::DATE . ' 22:00', '2026-10-06 03:00', true);

        $this->assertSame(5 * 60, $result['work_minutes']);
        $this->assertSame(60, $result['overtime_minutes']);
    }

    public function test_hours_are_not_reported_before_check_out(): void
    {
        $attendance = new Attendance(['attendance_date' => self::DATE, 'check_in_at' => Carbon::parse(self::DATE . ' 09:00'), 'status' => 'Present']);
        $shift = new StaffSchedule(['day_of_week' => 'Monday', 'start_time' => '09:00', 'end_time' => '18:00', 'is_day_off' => false]);

        $result = (new AttendanceStatusCalculator)->resolve(new Staff, $attendance, self::DATE, collect([$shift]), null, Carbon::parse(self::DATE . ' 12:00'));

        $this->assertNull($result['work_minutes']);
        $this->assertNull($result['overtime_minutes']);
    }
}
