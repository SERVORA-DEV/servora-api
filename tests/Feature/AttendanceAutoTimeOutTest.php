<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Service\Business\AttendanceRollover;
use App\Service\Business\FrontOfficeAttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Shifts nobody timed out of (AttendanceRollover):
//  - a branch with hours: timed out at closing, not timed in again;
//  - a 24-hour branch: stays timed in past the shift, with continuous shifts
//    on different dates split so the two shifts have separate records;
//  - their day off, or no shift: left for the manager.
//
// Needs PostgreSQL (see phpunit.xml): the local throwaway database only.
class AttendanceAutoTimeOutTest extends TestCase
{
    use RefreshDatabase;

    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private SpaBranch $branch;
    private Staff $staff;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'business_owner']);
        $business = SpaBusiness::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $business->id]);
        $this->staff = Staff::factory()->create(['spa_branch_id' => $this->branch->id, 'role' => 'therapist', 'status' => 'active']);
    }

    private function hours(string $open, string $close): void
    {
        foreach (self::DAYS as $day) {
            BranchSchedule::updateOrCreate(
                ['spa_branch_id' => $this->branch->id, 'day_of_week' => $day],
                ['opening_time' => $open, 'closing_time' => $close, 'is_closed' => false],
            );
        }
    }

    /** @param  array<string, array{0: string, 1: string}|null>  $byDay  null = day off */
    private function shifts(array $byDay): void
    {
        foreach ($byDay as $day => $times) {
            StaffSchedule::create($times === null
                ? ['staff_id' => $this->staff->id, 'day_of_week' => $day, 'is_day_off' => true]
                : ['staff_id' => $this->staff->id, 'day_of_week' => $day, 'start_time' => $times[0], 'end_time' => $times[1]]);
        }
    }

    private function timedIn(string $date, string $at): Attendance
    {
        return Attendance::create([
            'staff_id' => $this->staff->id,
            'attendance_date' => $date,
            'check_in_at' => Carbon::parse($at),
            'status' => 'Present',
        ]);
    }

    private function sweepAt(string $now): void
    {
        app(AttendanceRollover::class)->forBranches([$this->branch->id], Carbon::parse($now));
    }

    // ── A branch with hours ─────────────────────────────────────────────

    public function test_closing_time_ends_the_shift_and_nobody_is_timed_in_again(): void
    {
        $this->hours('09:00', '21:00');
        $this->shifts(['Saturday' => ['09:00', '18:00'], 'Sunday' => ['09:00', '18:00']]);
        // Saturday Nov 21: in at 9 AM, never timed out.
        $row = $this->timedIn('2026-11-21', '2026-11-21 09:00');

        $this->sweepAt('2026-11-21 20:00'); // still open
        $this->assertNull($row->fresh()->check_out_at);

        $this->sweepAt('2026-11-21 21:05');
        $this->assertEquals(Carbon::parse('2026-11-21 21:00'), $row->fresh()->check_out_at);
        $this->assertStringContainsString(AttendanceRollover::CLOSED_NOTE, $row->fresh()->remarks);

        // The next morning nobody is timed in for them.
        $this->sweepAt('2026-11-22 10:00');
        $this->assertSame(0, Attendance::where('attendance_date', '2026-11-22')->count());
    }

    public function test_a_branch_open_past_midnight_ends_at_its_closing_the_next_morning(): void
    {
        $this->hours('18:00', '02:00');
        $row = $this->timedIn('2026-11-21', '2026-11-21 18:00');

        $this->sweepAt('2026-11-22 01:30');
        $this->assertNull($row->fresh()->check_out_at);

        $this->sweepAt('2026-11-22 02:10');
        $this->assertEquals(Carbon::parse('2026-11-22 02:00'), $row->fresh()->check_out_at);
    }

    // ── Open 24 hours ───────────────────────────────────────────────────

    public function test_at_a_24_hour_branch_staff_can_time_out_after_an_overnight_shift(): void
    {
        $this->hours('00:00', '00:00');
        $this->shifts(['Saturday' => ['22:00', '06:00'], 'Sunday' => ['22:00', '06:00']]);
        $row = $this->timedIn('2026-11-21', '2026-11-21 22:00');

        // Past midnight, still in their shift: not split at midnight.
        $this->sweepAt('2026-11-22 00:30');
        $this->assertNull($row->fresh()->check_out_at);
        $this->assertSame(0, Attendance::where('attendance_date', '2026-11-22')->count());

        $this->sweepAt('2026-11-22 06:15');
        $this->assertNull($row->fresh()->check_out_at);
        $this->assertSame(0, Attendance::where('attendance_date', '2026-11-22')->count());

        $this->travelTo(Carbon::parse('2026-11-22 07:00'));
        $desk = app(FrontOfficeAttendanceService::class);
        $roster = $desk->today($this->owner);
        $this->assertSame($row->uuid, $roster['data'][0]['attendance_uuid']);
        $this->assertNull($roster['data'][0]['check_out_at']);
        $this->assertSame('Present', $roster['data'][0]['status']);

        $result = $desk->checkOut($this->owner, $this->staff->uuid);
        $this->assertSame('Timed out.', $result['message']);

        $row->refresh();
        $this->assertSame(9 * 60, (int) $row->work_minutes);
        $this->assertSame(60, (int) $row->overtime_minutes);
        $this->assertSame(0, Attendance::where('attendance_date', '2026-11-22')->count());
    }

    public function test_at_a_24_hour_branch_a_back_to_back_shift_starts_again(): void
    {
        $this->hours('00:00', '00:00');
        $this->shifts(['Saturday' => ['00:00', '00:00'], 'Sunday' => ['00:00', '00:00']]);
        $row = $this->timedIn('2026-11-21', '2026-11-21 00:00');

        $this->sweepAt('2026-11-22 03:00');

        $this->assertEquals(Carbon::parse('2026-11-22 00:00'), $row->fresh()->check_out_at);
        $next = Attendance::where('attendance_date', '2026-11-22')->sole();
        $this->assertEquals(Carbon::parse('2026-11-22 00:00'), $next->check_in_at);
        $this->assertNull($next->check_out_at);
        $this->assertSame('Present', $next->status);
        $this->assertStringContainsString(AttendanceRollover::NEXT_SHIFT_NOTE, $next->remarks);
    }

    public function test_at_a_24_hour_branch_a_day_off_is_left_for_the_manager(): void
    {
        $this->hours('00:00', '00:00');
        $this->shifts(['Saturday' => null]);
        // In on their day off (a fill-in), never timed out.
        $row = $this->timedIn('2026-11-21', '2026-11-21 10:00');

        $this->sweepAt('2026-11-23 10:00');

        $this->assertNull($row->fresh()->check_out_at);
    }

    public function test_at_a_24_hour_branch_no_schedule_is_left_for_the_manager(): void
    {
        $this->hours('00:00', '00:00');
        $row = $this->timedIn('2026-11-21', '2026-11-21 10:00');

        $this->sweepAt('2026-11-23 10:00');

        $this->assertNull($row->fresh()->check_out_at);
    }
}
