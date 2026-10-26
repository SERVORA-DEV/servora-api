<?php

namespace App\Service\Business;

use App\Models\Attendance;
use App\Models\Staff;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

// Staff are never timed out at closing time or at the end of their shift. Someone
// still timed in when the branch opens the next day is timed out at that opening,
// and timed in for the new working day at the same moment — on the floor without a
// gap: in 4 PM Oct 24 → out 2 PM Oct 25 (the branch opens), in 2 PM Oct 25 → and so
// on, day after day, until someone times them out. (App\Support\BusinessDay says when
// a day ends.) An older day left open is only closed at its next opening.
//
// Runs where the day's attendance is read (the front desk's roster and its time
// in/out) and with DayCloser (the scheduler, and the bell's checks), so it works
// where no scheduler runs. Cheap when there is nothing to carry: one query.
class AttendanceRollover
{
    // Days carried in one go, should nobody have been in for a while.
    private const MAX_DAYS = 14;

    public function __construct(private AttendanceStatusCalculator $statusCalculator)
    {
    }

    /** @param  int[]  $branchIds  @return int  how many days were carried over */
    public function forBranches(array $branchIds, ?CarbonInterface $now = null): int
    {
        $now ??= now();
        if (! $branchIds) {
            return 0;
        }

        // Still timed in on a day before today's calendar date: the only ones whose working day can have ended.
        $open = Attendance::whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->where('attendance_date', '<', $now->toDateString())
            ->whereIn('staff_id', Staff::whereIn('spa_branch_id', $branchIds)->select('id'))
            ->with('staff.schedules')
            ->get();

        $carried = 0;
        foreach ($open as $attendance) {
            $carried += $this->carry($attendance, $now);
        }

        return $carried;
    }

    private function carry(Attendance $attendance, CarbonInterface $now): int
    {
        $staff = $attendance->staff;
        $branchId = $attendance->spa_branch_id ?: $staff?->spa_branch_id;
        if (! $staff || ! $branchId) {
            return 0;
        }

        $week = BusinessDay::week($branchId);
        $today = BusinessDay::current($week, $now);
        $carried = 0;

        while ($carried < self::MAX_DAYS && $attendance->attendance_date->toDateString() < $today) {
            $from = $attendance->attendance_date->toDateString();
            $opens = BusinessDay::nextOpening($week, $from);
            if (! $opens || $opens->gt($now)) {
                break;
            }
            $date = $opens->toDateString();

            $next = DB::transaction(function () use ($attendance, $staff, $opens, $date, $from, $today) {
                $attendance->update([
                    'check_out_at' => $opens,
                    'remarks' => self::note($attendance->remarks, 'Timed out when the branch opened the next day'),
                ]);

                // Carried into the day that is running now, never into days already past: a
                // time-out forgotten a week ago is closed, not turned into a week of shifts.
                if ($date !== $today) {
                    return null;
                }

                $existing = Attendance::where('staff_id', $staff->id)->where('attendance_date', $date)->first();
                // They were already timed in (or marked) for the new day: nothing to carry into it.
                if ($existing && ($existing->check_in_at || $existing->status === 'On Leave')) {
                    return null;
                }

                $hasShift = $this->statusCalculator->hasWorkingScheduleForDate($staff->schedules, $date);

                return Attendance::updateOrCreate(
                    ['staff_id' => $staff->id, 'attendance_date' => $date],
                    [
                        'check_in_at' => $opens,
                        'check_out_at' => null,
                        'status' => $hasShift ? 'Present' : 'Fill In',
                        'left_early' => false,
                        'remarks' => self::note($existing?->remarks, 'Still on the floor from ' . \Carbon\Carbon::parse($from)->format('M j') . ': timed in when the branch opened'),
                        'created_by' => $existing?->created_by ?? $attendance->created_by,
                        'updated_by' => $attendance->updated_by,
                    ],
                );
            });

            $carried++;
            if (! $next) {
                break;
            }
            $attendance = $next->setRelation('staff', $staff);
        }

        return $carried;
    }

    private static function note(?string $existing, string $note): string
    {
        $existing = trim((string) $existing);

        return $existing !== '' && ! str_contains($existing, $note) ? "{$existing} · {$note}" : ($existing ?: $note);
    }
}
