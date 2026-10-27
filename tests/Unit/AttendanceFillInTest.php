<?php

namespace Tests\Unit;

use App\Models\Attendance;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Service\Business\AttendanceStatusCalculator;
use Carbon\Carbon;
use Tests\TestCase;

// A fill-in at the front desk is one of two things:
//   - covering for a therapist: it takes THEIR shift (start, end, overtime past
//     it) and is never counted late, however late they come in;
//   - just filling in for lack of staff: no shift of its own, so nothing to be
//     late for and every hour counts.
//
// No database: the calculator only reads the models it is handed.
//   php artisan test --filter=AttendanceFillInTest
class AttendanceFillInTest extends TestCase
{
    private const DATE = '2026-10-05'; // a Monday

    private function shift(string $from, string $to): StaffSchedule
    {
        return new StaffSchedule(['day_of_week' => 'Monday', 'start_time' => $from, 'end_time' => $to, 'is_day_off' => false]);
    }

    private function resolve(?StaffSchedule $own, ?StaffSchedule $covered, string $in, ?string $out = null): array
    {
        $attendance = new Attendance([
            'attendance_date' => self::DATE,
            'check_in_at' => Carbon::parse($in),
            'check_out_at' => $out ? Carbon::parse($out) : null,
            'status' => 'Fill In',
        ]);
        if ($covered) {
            $attendance->covering_for_staff_id = 99;
            $coveredStaff = new Staff;
            $coveredStaff->setRelation('schedules', collect([$covered]));
            $attendance->setRelation('coveringFor', $coveredStaff);
        }

        return (new AttendanceStatusCalculator)->resolve(
            new Staff,
            $attendance,
            self::DATE,
            $own ? collect([$own]) : collect(),
            null,
            Carbon::parse(self::DATE . ' 12:00'),
        );
    }

    public function test_a_cover_takes_the_shift_of_the_one_they_cover(): void
    {
        $r = $this->resolve(null, $this->shift('10:00', '18:00'), self::DATE . ' 11:30');

        $this->assertSame('Fill In', $r['status']);
        $this->assertSame('10:00', $r['scheduled_start']);
        $this->assertSame('18:00', $r['scheduled_end']);
    }

    public function test_a_cover_is_never_late_for_the_shift_they_take(): void
    {
        // An hour and a half after the covered shift began.
        $r = $this->resolve(null, $this->shift('10:00', '18:00'), self::DATE . ' 11:30');

        $this->assertNull($r['late_minutes']);
        $this->assertNotSame('Late', $r['status']);
    }

    public function test_a_cover_scheduled_themselves_works_the_covered_shift_not_their_own(): void
    {
        $r = $this->resolve($this->shift('13:00', '21:00'), $this->shift('10:00', '18:00'), self::DATE . ' 10:40');

        $this->assertSame('10:00', $r['scheduled_start']);
        $this->assertSame('18:00', $r['scheduled_end']);
        $this->assertNull($r['late_minutes']);
    }

    public function test_time_past_the_covered_shift_is_overtime(): void
    {
        $r = $this->resolve(null, $this->shift('10:00', '18:00'), self::DATE . ' 10:00', self::DATE . ' 19:30');

        $this->assertSame(90, $r['overtime_minutes']);
    }

    public function test_just_filling_in_has_no_shift_and_every_hour_counts(): void
    {
        $r = $this->resolve(null, null, self::DATE . ' 11:30', self::DATE . ' 19:30');

        $this->assertSame('Fill In', $r['status']);
        $this->assertNull($r['scheduled_start']);
        $this->assertNull($r['late_minutes']);
        $this->assertSame(8 * 60, $r['work_minutes']);
        $this->assertSame(0, $r['overtime_minutes']);
    }
}
