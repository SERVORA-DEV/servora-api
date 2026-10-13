<?php

namespace App\Service\Business;

use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\SpaBusiness;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Support\AttendanceKept;
use App\Support\History;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Single source of truth for a (staff, date) attendance status, late
// minutes, and work minutes — called from every read and write path
// (roster listing, detail view, correction, CSV export) so nothing derives
// it differently in two places. Safe to call for a date with no Attendance
// row at all.
//
// A day that has happened stays as it was (App\Support\History): the shift
// and how late the person was are kept on the attendance row when they check
// in, the hours worked and overtime when they check out — keep(), called as
// the row is saved. resolve() gives those back, so changing the late
// threshold, the overtime switch or a branch's closing time afterwards only
// applies to days still to come. A manager's correction of the times is a new
// save, so it is worked out again under the settings in force then.
class AttendanceStatusCalculator
{
    // Minutes of grace after the scheduled start before a check-in counts
    // as Late — absorbs clock skew/rounding rather than flagging every
    // on-time arrival. Callers pass the owner's Staff Policies
    // late_threshold_minutes when they have it (see graceMinutesFor()).
    private const LATE_GRACE_MINUTES = 5;

    /**
     * The owner's late threshold (Settings → Staff Policies), or the default
     * grace. With attendance tracking switched off nobody is held to a start
     * time, so the grace covers the whole day and no check-in reads as Late.
     */
    public static function graceMinutesFor(?SpaBusiness $business): int
    {
        $policy = $business?->settings?->section('staff_policy') ?? [];

        if (! ($policy['attendance_tracking'] ?? true)) {
            return 24 * 60;
        }

        return (int) ($policy['late_threshold_minutes'] ?? self::LATE_GRACE_MINUTES);
    }

    /**
     * The owner's Overtime policy (Settings → Staff Policies). Off: worked
     * hours stop counting at the end of the person's shift. On: time past the
     * shift end counts, and is reported separately as overtime. Only applies
     * while attendance tracking is on — with tracking off nobody is held to
     * shift times, so every minute simply counts.
     */
    public static function overtimeAllowedFor(?SpaBusiness $business): bool
    {
        $policy = $business?->settings?->section('staff_policy') ?? [];

        if (! ($policy['attendance_tracking'] ?? true)) {
            return true;
        }

        return (bool) ($policy['allow_overtime'] ?? false);
    }

    /**
     * @param  Collection<int, StaffSchedule>  $staffSchedules  all of this staff member's schedule rows (any day/version) — matched internally by day_of_week + effective_from/until for $date.
     * @param  bool  $allowOvertime  see overtimeAllowedFor(); defaults to counting everything, for callers that only want the status.
     */
    public function resolve(
        Staff $staff,
        ?Attendance $attendance,
        string $date,
        Collection $staffSchedules,
        ?BranchSchedule $branchSchedule = null,
        ?Carbon $now = null,
        ?int $graceMinutes = null,
        bool $allowOvertime = true,
    ): array {
        $live = $this->compute($staff, $attendance, $date, $staffSchedules, $branchSchedule, $now, $graceMinutes, $allowOvertime);

        return $attendance ? AttendanceKept::apply($live, self::keptOf($attendance)) : $live;
    }

    /** What the row has kept of its own day — null when nothing has been. */
    private static function keptOf(Attendance $attendance): ?array
    {
        if ($attendance->resolved_status === null) {
            return null;
        }

        return [
            'status' => $attendance->resolved_status,
            'late_minutes' => $attendance->late_minutes !== null ? (int) $attendance->late_minutes : null,
            'work_minutes' => $attendance->work_minutes !== null ? (int) $attendance->work_minutes : null,
            'overtime_minutes' => $attendance->overtime_minutes !== null ? (int) $attendance->overtime_minutes : null,
            'scheduled_start' => $attendance->scheduled_start,
            'scheduled_end' => $attendance->scheduled_end,
            'checked_in' => $attendance->check_in_at !== null,
            'checked_out' => $attendance->check_out_at !== null,
        ];
    }

    /**
     * Keeps the day's result on the attendance row as it is being saved
     * (Attendance's saving hook): the shift and lateness when the check-in is
     * set or changed, the hours and overtime when the check-out is. A save
     * that touches none of those (a remark) keeps what is there.
     */
    public function keep(Attendance $attendance, bool $force = false): void
    {
        if (! History::ready()) {
            return;
        }
        $touched = ! $attendance->exists || $attendance->isDirty(['check_in_at', 'check_out_at', 'status', 'left_early']);
        if (! $force && ! $touched && $attendance->resolved_status !== null) {
            return;
        }

        $staff = Staff::withTrashed()->with(['schedules', 'branch.business.settings'])->find($attendance->staff_id);
        if (! $staff) {
            return;
        }
        $date = Carbon::parse($attendance->attendance_date)->format('Y-m-d');
        $branchSchedule = BranchSchedule::where('spa_branch_id', $staff->spa_branch_id)
            ->where('day_of_week', Carbon::parse($date)->format('l'))
            ->first();
        $business = $staff->branch?->business;

        $live = $this->compute(
            $staff, $attendance, $date, $staff->schedules, $branchSchedule, null,
            self::graceMinutesFor($business), self::overtimeAllowedFor($business),
        );

        // The shift and the lateness belong to the check-in: taken again only
        // when the check-in (or the day's status) is what changed.
        $againFromCheckIn = $force || ! $attendance->exists || $attendance->resolved_status === null
            || $attendance->isDirty(['check_in_at', 'status']);
        $kept = AttendanceKept::toKeep($live, $againFromCheckIn ? null : [
            'late_minutes' => $attendance->late_minutes !== null ? (int) $attendance->late_minutes : null,
            'scheduled_start' => $attendance->scheduled_start,
            'scheduled_end' => $attendance->scheduled_end,
        ]);

        $attendance->forceFill([
            'resolved_status' => $kept['status'],
            'late_minutes' => $kept['late_minutes'],
            'work_minutes' => $kept['work_minutes'],
            'overtime_minutes' => $kept['overtime_minutes'],
            'scheduled_start' => $kept['scheduled_start'],
            'scheduled_end' => $kept['scheduled_end'],
        ]);
    }

    // The day worked out from the schedules and settings as they are now.
    private function compute(
        Staff $staff,
        ?Attendance $attendance,
        string $date,
        Collection $staffSchedules,
        ?BranchSchedule $branchSchedule = null,
        ?Carbon $now = null,
        ?int $graceMinutes = null,
        bool $allowOvertime = true,
    ): array {
        $now = $now ?: Carbon::now();
        $graceMinutes ??= self::LATE_GRACE_MINUTES;
        $schedule = $this->matchSchedule($staffSchedules, $date);
        $isDayOff = (bool) ($schedule?->is_day_off);

        $scheduledStart = ($schedule && ! $isDayOff && $schedule->start_time)
            ? Carbon::parse("{$date} {$schedule->start_time}")
            : null;

        $scheduledEnd = ($schedule && ! $isDayOff && $schedule->end_time)
            ? Carbon::parse("{$date} {$schedule->end_time}")
            : (($branchSchedule && ! $branchSchedule->is_closed && $branchSchedule->closing_time)
                ? Carbon::parse("{$date} {$branchSchedule->closing_time}")
                : null);

        // Falling back to the branch's closing time: a closing time at or
        // before the opening time means the branch is open 24 hours or
        // closes the next day (same convention as
        // AppointmentAvailabilityService::branchIsOpen), so the day ends
        // tomorrow — not before it has even opened.
        $endsWithBranch = ! ($schedule && ! $isDayOff && $schedule->end_time);

        // Their own shift ending at or before it starts (10 PM – 2 AM) ends
        // the next day.
        if ($scheduledEnd && ! $endsWithBranch && $scheduledStart && $scheduledEnd->lte($scheduledStart)) {
            $scheduledEnd->addDay();
        }

        if ($scheduledEnd && $endsWithBranch && $branchSchedule?->opening_time
            && $scheduledEnd->lte(Carbon::parse("{$date} {$branchSchedule->opening_time}"))) {
            $scheduledEnd->addDay();
        }

        // An end/closing time of exactly midnight means the end of this
        // calendar day (e.g. a branch open until midnight), not its start —
        // Carbon::parse("$date 00:00:00") lands on the start of $date, which
        // would otherwise make the shift look already-ended the instant the
        // day begins. Push it to the start of the next day instead.
        if ($scheduledEnd && $scheduledEnd->format('H:i:s') === '00:00:00' && $scheduledEnd->toDateString() === $date) {
            $scheduledEnd->addDay();
        }

        $checkIn = $attendance?->check_in_at;
        $checkOut = $attendance?->check_out_at;
        $storedStatus = $attendance?->status;

        // Overtime is measured against the person's OWN shift end. Someone
        // with no shift end on record (no schedule, a day off, a fill-in)
        // has no line to cross, so all of their time counts as worked.
        $shiftEnd = $endsWithBranch ? null : $scheduledEnd;
        [$workMinutes, $overtimeMinutes] = $this->workAndOvertime($checkIn, $checkOut, $schedule, $shiftEnd, $allowOvertime);

        // Explicit human decisions pass through untouched — never
        // second-guessed by the calculator.
        if (in_array($storedStatus, ['On Leave', 'Holiday', 'Half Day'], true)) {
            return $this->result($storedStatus, null, $workMinutes, $scheduledStart, $scheduledEnd, $isDayOff, false, false, $overtimeMinutes);
        }

        if ($storedStatus === 'Absent' && ! $checkIn) {
            return $this->result('Absent', null, null, $scheduledStart, $scheduledEnd, $isDayOff, true, false);
        }

        // Checked in — a real fact, computed regardless of whether a
        // schedule exists for this date.
        if ($checkIn) {
            $lateMinutes = null;
            // A Front Desk fill-in check-in (no matching StaffSchedule row —
            // see FrontOfficeAttendanceService::checkIn) is stored as 'Fill
            // In' and must pass through as-is; every other stored status
            // here is recomputed fresh from the check-in fact.
            $status = $storedStatus === 'Fill In' ? 'Fill In' : 'Present';

            // A fill-in came in to cover, not for their own shift — never Late.
            if ($status !== 'Fill In' && $scheduledStart && $checkIn->gt($scheduledStart->copy()->addMinutes($graceMinutes))) {
                $lateMinutes = (int) $scheduledStart->diffInMinutes($checkIn);
                $status = 'Late';
            }

            if (! $checkOut) {
                // Someone who checked in after the shift/branch end (an
                // after-hours fill-in) is only Incomplete once the calendar
                // day is over — not the instant they check in.
                $cutoff = ($scheduledEnd && $checkIn->lt($scheduledEnd)) ? $scheduledEnd : null;
                if ($this->dayHasEnded($date, $cutoff, $now)) {
                    return $this->result('Incomplete', $lateMinutes, null, $scheduledStart, $scheduledEnd, $isDayOff, false, true);
                }

                return $this->result($status, $lateMinutes, null, $scheduledStart, $scheduledEnd, $isDayOff, false, false);
            }

            // Sent home / left early at the front desk (FrontOfficeAttendanceService::leave).
            if ($attendance?->left_early) {
                $status = 'Left Early';
            }

            return $this->result($status, $lateMinutes, $workMinutes, $scheduledStart, $scheduledEnd, $isDayOff, false, false, $overtimeMinutes);
        }

        // No check-in at all. A day off, or no StaffSchedule row for this
        // date, means nothing is expected of them — never flagged Absent.
        // Branch opening hours alone don't put someone on shift; only their
        // own schedule does (an unscheduled therapist can still come in as a
        // Fill In, but not coming in isn't an absence).
        if ($isDayOff || ! $schedule) {
            return $this->result(null, null, null, $scheduledStart, $scheduledEnd, $isDayOff, false, false);
        }

        // Nor is anything expected for a shift that began before they were
        // in Servora at all: an employee added today did not miss last
        // month. Counted from when their record was added, not the hire date
        // on it (a spa that starts using Servora enters staff hired years
        // ago). A record someone did make for such a day is handled above.
        if ($this->beganBeforeTheyWereAdded($staff, $scheduledStart, $date)) {
            return $this->result(null, null, null, null, null, false, false, false);
        }

        // Only becomes Absent once the shift/day has actually passed —
        // never just because there's no check-in yet.
        if ($this->dayHasEnded($date, $scheduledEnd, $now)) {
            return $this->result('Absent', null, null, $scheduledStart, $scheduledEnd, $isDayOff, true, false);
        }

        return $this->result(null, null, null, $scheduledStart, $scheduledEnd, $isDayOff, false, false);
    }

    // Exposes matchSchedule() to callers that only need a yes/no answer —
    // FrontOfficeAttendanceService::checkIn uses this to decide 'Present'
    // vs. 'Fill In' with the exact same matching rule the roster read path
    // already uses to label a row "No Schedule Today".
    public function hasScheduleForDate(Collection $staffSchedules, string $date): bool
    {
        return $this->matchSchedule($staffSchedules, $date) !== null;
    }

    // A real working shift on $date — a day-off row doesn't count. Decides
    // 'Present' vs 'Fill In' on a front-desk check-in: someone coming in on
    // their day off is covering, same as someone with no schedule at all.
    public function hasWorkingScheduleForDate(Collection $staffSchedules, string $date): bool
    {
        $schedule = $this->matchSchedule($staffSchedules, $date);

        return $schedule !== null && ! $schedule->is_day_off;
    }

    private function matchSchedule(Collection $staffSchedules, string $date): ?StaffSchedule
    {
        $dayOfWeek = Carbon::parse($date)->format('l');

        return $staffSchedules
            ->filter(fn (StaffSchedule $s) => $s->day_of_week === $dayOfWeek)
            ->filter(fn (StaffSchedule $s) => ! $s->effective_from || $s->effective_from->lte($date))
            ->filter(fn (StaffSchedule $s) => ! $s->effective_until || $s->effective_until->gte($date))
            ->first();
    }

    private function dayHasEnded(string $date, ?Carbon $scheduledEnd, Carbon $now): bool
    {
        $targetDate = Carbon::parse($date)->startOfDay();
        $today = $now->copy()->startOfDay();

        if ($targetDate->lt($today)) {
            return true;
        }

        if ($targetDate->gt($today)) {
            return false;
        }

        // Same calendar day: ended once we're past the scheduled/branch end
        // time, or past end of day if neither is known.
        $cutoff = $scheduledEnd ?: $targetDate->copy()->endOfDay();

        return $now->gte($cutoff);
    }

    /**
     * [worked minutes, overtime minutes] — both null until there is a check-in
     * and a check-out. Overtime is the time worked after $shiftEnd. With
     * overtime not allowed it is dropped: worked minutes stop at the shift
     * end and overtime is 0. With it allowed, worked minutes are the whole
     * stretch and overtime says how much of that was past the shift.
     */
    private function workAndOvertime(?Carbon $checkIn, ?Carbon $checkOut, ?StaffSchedule $schedule, ?Carbon $shiftEnd, bool $allowOvertime): array
    {
        $total = $this->workMinutes($checkIn, $checkOut, $schedule);

        if ($total === null) {
            return [null, null];
        }

        $overtime = 0;
        if ($shiftEnd && $checkOut->gt($shiftEnd)) {
            // Checked in after the shift had already ended: all of it is extra.
            $from = $checkIn->gt($shiftEnd) ? $checkIn : $shiftEnd;
            $overtime = min($total, intdiv($checkOut->getTimestamp() - $from->getTimestamp(), 60));
        }

        return $allowOvertime ? [$total, $overtime] : [$total - $overtime, 0];
    }

    private function workMinutes(?Carbon $checkIn, ?Carbon $checkOut, ?StaffSchedule $schedule): ?int
    {
        if (! $checkIn || ! $checkOut) {
            return null;
        }

        $minutes = intdiv($checkOut->getTimestamp() - $checkIn->getTimestamp(), 60);
        if ($minutes < 0) {
            // Overnight shift crossing midnight.
            $minutes += 24 * 60;
        }

        // Time out minus time in: schedules have no break to take off.
        return max(0, $minutes);
    }

    private function beganBeforeTheyWereAdded(Staff $staff, ?Carbon $scheduledStart, string $date): bool
    {
        $added = $staff->created_at;
        if (! $added) {
            return false;
        }

        // A shift with no start time of its own is taken to start with the day.
        return ($scheduledStart ?? Carbon::parse($date)->startOfDay())->lt($added);
    }

    private function result(
        ?string $status,
        ?int $lateMinutes,
        ?int $workMinutes,
        ?Carbon $scheduledStart,
        ?Carbon $scheduledEnd,
        bool $isDayOff,
        bool $missingCheckIn,
        bool $missingCheckOut,
        ?int $overtimeMinutes = null,
    ): array {
        return [
            'status' => $status,
            'late_minutes' => $lateMinutes,
            'work_minutes' => $workMinutes,
            // Part of work_minutes that was past the shift end (0 when the
            // Overtime policy is off, null until checked out).
            'overtime_minutes' => $workMinutes === null ? null : (int) $overtimeMinutes,
            'scheduled_start' => $scheduledStart?->format('H:i'),
            'scheduled_end' => $scheduledEnd?->format('H:i'),
            'is_day_off' => $isDayOff,
            'missing_check_in' => $missingCheckIn,
            'missing_check_out' => $missingCheckOut,
        ];
    }
}
