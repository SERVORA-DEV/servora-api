<?php

namespace App\Service\Business;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\SpaBranch;
use App\Models\Staff;
use App\Support\Branches\BusinessDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

// Ends a shift nobody timed out of:
//
//  - A branch with opening hours: at closing time (the next morning for a branch open past
//    midnight). Where the business allows overtime and the person's own shift ends later,
//    at the end of their shift instead. They are not timed in again: the next day they time
//    in themselves.
//  - A branch open 24 hours (equal opening and closing times), closed that day, or with no
//    hours entered: at the end of their own shift that day. When their next shift starts
//    right then (a 24-hour shift every day, a night shift straight into a day shift), they
//    are timed in for it at that same moment, so there is no gap on the floor.
//  - No shift that day (their day off, or no schedule: a fill-in), or they came in after the
//    time it would end: left as it is, for the manager to correct.
//
// Runs where the day's attendance is read (the front desk's roster and its time in/out) and
// with DayCloser (the scheduler every ten minutes, and the bell's checks), so it works where
// no scheduler runs. Cheap when there is nothing to end: one query.
class AttendanceRollover
{
    // How far back an open time in is looked at, and the most shifts carried in one go.
    private const MAX_DAYS = 14;

    public const CLOSED_NOTE = 'Timed out automatically when the branch closed';
    public const SHIFT_END_NOTE = 'Timed out automatically at the end of their shift';
    public const NEXT_SHIFT_NOTE = 'Timed in automatically for their next shift (still on the floor)';

    /** @var array<int, ?SpaBranch> */
    private array $branches = [];

    public function __construct(private AttendanceStatusCalculator $statusCalculator)
    {
    }

    /** @param  int[]  $branchIds  @return int  how many shifts were ended */
    public function forBranches(array $branchIds, ?CarbonInterface $now = null): int
    {
        $now ??= now();
        if (! $branchIds) {
            return 0;
        }

        $open = Attendance::whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->whereBetween('attendance_date', [$now->copy()->subDays(self::MAX_DAYS + 1)->toDateString(), $now->toDateString()])
            ->whereIn('staff_id', Staff::whereIn('spa_branch_id', $branchIds)->select('id'))
            ->with('staff.schedules')
            ->get();

        $ended = 0;
        foreach ($open as $attendance) {
            $ended += $this->end($attendance, $now);
        }

        return $ended;
    }

    private function end(Attendance $attendance, CarbonInterface $now): int
    {
        $staff = $attendance->staff;
        $branchId = $attendance->spa_branch_id ?: $staff?->spa_branch_id;
        if (! $staff || ! $branchId) {
            return 0;
        }

        $week = BusinessDay::week($branchId);
        $ended = 0;

        while ($ended < self::MAX_DAYS) {
            $date = $attendance->attendance_date->toDateString();
            [$endAt, $note, $continues] = $this->endOf($attendance, $staff, $week, $branchId, $date);

            // Nothing to end it by (no shift that day), it hasn't come yet, or they came in after it.
            if (! $endAt || $endAt->gt($now) || ! $attendance->check_in_at || $endAt->lte($attendance->check_in_at)) {
                break;
            }

            $next = DB::transaction(function () use ($attendance, $staff, $endAt, $note, $continues) {
                $attendance->update([
                    'check_out_at' => $endAt,
                    'remarks' => self::note($attendance->remarks, $note),
                ]);
                if (! $continues) {
                    return null;
                }

                // Their next shift starts right as this one ends: on the floor without a gap.
                $nextDate = $endAt->toDateString();
                $existing = Attendance::where('staff_id', $staff->id)->where('attendance_date', $nextDate)->first();
                if ($existing && ($existing->check_in_at || $existing->status === AttendanceStatus::OnLeave->value)) {
                    return null;
                }

                return Attendance::updateOrCreate(
                    ['staff_id' => $staff->id, 'attendance_date' => $nextDate],
                    [
                        'check_in_at' => $endAt,
                        'check_out_at' => null,
                        'status' => AttendanceStatus::Present->value,
                        'left_early' => false,
                        'remarks' => self::note($existing?->remarks, self::NEXT_SHIFT_NOTE),
                        'created_by' => $existing?->created_by ?? $attendance->created_by,
                        'updated_by' => $attendance->updated_by,
                    ],
                );
            });

            $ended++;
            if (! $next) {
                break;
            }
            $attendance = $next->setRelation('staff', $staff);
        }

        return $ended;
    }

    /**
     * When this day's attendance ends, why, and whether the next shift carries on from it.
     *
     * @return array{0: ?Carbon, 1: string, 2: bool}
     */
    private function endOf(Attendance $attendance, Staff $staff, array $week, int $branchId, string $date): array
    {
        $day = $week[Carbon::parse($date)->format('l')] ?? null;
        $shift = $this->statusCalculator->shiftWindow($staff->schedules, $date);
        $midnight = Carbon::parse($date)->startOfDay();
        $shiftEnd = $shift ? $midnight->copy()->addMinutes($shift[1]) : null;

        $open = $day && ! $day['is_closed'] && $day['opening_time'] ? $this->minutes($day['opening_time']) : null;
        $close = $day && ! $day['is_closed'] && ($day['closing_time'] ?? null) ? $this->minutes($day['closing_time']) : null;

        // A branch with opening hours that day: closing time ends it.
        if ($open !== null && $close !== null && $open !== $close) {
            $closesAt = $midnight->copy()->addMinutes($close <= $open ? $close + 1440 : $close);
            if ($shiftEnd && $shiftEnd->gt($closesAt) && AttendanceStatusCalculator::overtimeAllowedFor($this->branch($branchId)?->business)) {
                return [$shiftEnd, self::SHIFT_END_NOTE, false];
            }

            return [$closesAt, self::CLOSED_NOTE, false];
        }

        // Open 24 hours, closed that day, or no hours: their own shift ends it; none, it is left.
        if (! $shiftEnd) {
            return [null, '', false];
        }

        return [$shiftEnd, self::SHIFT_END_NOTE, $this->nextShiftStartsAt($staff, $shiftEnd)];
    }

    // Whether they have a working shift starting exactly at $at (the day $at falls on).
    private function nextShiftStartsAt(Staff $staff, Carbon $at): bool
    {
        $next = $this->statusCalculator->shiftWindow($staff->schedules, $at->toDateString());

        return $next !== null && $next[0] === $at->hour * 60 + $at->minute;
    }

    private function branch(int $branchId): ?SpaBranch
    {
        return $this->branches[$branchId] ??= SpaBranch::with('business.settings')->find($branchId);
    }

    private function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    private static function note(?string $existing, string $note): string
    {
        $existing = trim((string) $existing);

        return $existing !== '' && ! str_contains($existing, $note) ? "{$existing} · {$note}" : ($existing ?: $note);
    }
}
