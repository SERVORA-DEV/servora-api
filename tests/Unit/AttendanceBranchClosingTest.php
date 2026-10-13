<?php

namespace Tests\Unit;

use App\Models\BranchSchedule;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Service\Business\AttendanceStatusCalculator;
use Carbon\Carbon;
use Tests\TestCase;

// A staff member with no shift end of their own is held to the branch's
// closing time. For a branch that is open 24 hours (equal times) or closes
// after midnight (closing before opening), that closing time is TOMORROW —
// otherwise the shift would look over before it began and the person would
// be marked Absent while they could still walk in.
//
// No database: the calculator only reads the models it is handed.
//   php artisan test --filter=AttendanceBranchClosingTest
class AttendanceBranchClosingTest extends TestCase
{
    private const DATE = '2026-10-05'; // a Monday

    private function resolve(string $opens, string $closes, string $nowTime): array
    {
        $shift = new StaffSchedule(['day_of_week' => 'Monday', 'start_time' => $opens, 'end_time' => null, 'is_day_off' => false]);
        $branch = new BranchSchedule(['day_of_week' => 'Monday', 'opening_time' => $opens, 'closing_time' => $closes, 'is_closed' => false]);

        return (new AttendanceStatusCalculator)->resolve(
            new Staff,
            null,
            self::DATE,
            collect([$shift]),
            $branch,
            Carbon::parse(self::DATE . ' ' . $nowTime),
        );
    }

    public function test_an_overnight_branch_does_not_mark_staff_absent_before_it_closes(): void
    {
        // Open 10 PM – 2 AM; it is 11 PM and nobody has clocked in yet.
        $result = $this->resolve('22:00', '02:00', '23:00');

        $this->assertNull($result['status']);
        $this->assertFalse($result['missing_check_in']);
    }

    public function test_a_24_hour_branch_does_not_mark_staff_absent_during_the_day(): void
    {
        // Open 9 AM – 9 AM (24 hours); it is 3 PM.
        $result = $this->resolve('09:00', '09:00', '15:00');

        $this->assertNull($result['status']);
    }

    public function test_ordinary_hours_still_mark_staff_absent_after_closing(): void
    {
        // Open 9 AM – 6 PM; it is 8 PM and nobody clocked in.
        $result = $this->resolve('09:00', '18:00', '20:00');

        $this->assertSame('Absent', $result['status']);
    }
}
