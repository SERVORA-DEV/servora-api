<?php

namespace App\Service\Business;

use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\Staff;
use App\Models\StaffSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Single source of truth for a (staff, date) attendance status, late
// minutes, and work minutes — called from every read and write path
// (roster listing, detail view, correction, CSV export) so nothing derives
// it differently in two places. Deliberately stateless/pure: no DB writes,
// no side effects, safe to call for a date with no Attendance row at all.
class AttendanceStatusCalculator
{
    // Minutes of grace after the scheduled start before a check-in counts
    // as Late — absorbs clock skew/rounding rather than flagging every
    // on-time arrival.
    private const LATE_GRACE_MINUTES = 5;

    /**
     * @param  Collection<int, StaffSchedule>  $staffSchedules  all of this staff member's schedule rows (any day/version) — matched internally by day_of_week + effective_from/until for $date.
     */
    public function resolve(
        Staff $staff,
        ?Attendance $attendance,
        string $date,
        Collection $staffSchedules,
        ?BranchSchedule $branchSchedule = null,
        ?Carbon $now = null
    ): array {
        $now = $now ?: Carbon::now();
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

        // An end/closing time of exactly midnight means the end of this
        // calendar day (e.g. a branch open until midnight), not its start —
        // Carbon::parse("$date 00:00:00") lands on the start of $date, which
        // would otherwise make the shift look already-ended the instant the
        // day begins. Push it to the start of the next day instead.
        if ($scheduledEnd && $scheduledEnd->format('H:i:s') === '00:00:00') {
            $scheduledEnd->addDay();
        }

        $checkIn = $attendance?->check_in_at;
        $checkOut = $attendance?->check_out_at;
        $storedStatus = $attendance?->status;

        // Explicit human decisions pass through untouched — never
        // second-guessed by the calculator.
        if (in_array($storedStatus, ['On Leave', 'Holiday', 'Half Day'], true)) {
            return $this->result($storedStatus, null, $this->workMinutes($checkIn, $checkOut, $schedule), $scheduledStart, $scheduledEnd, $isDayOff, false, false);
        }

        if ($storedStatus === 'Absent' && ! $checkIn) {
            return $this->result('Absent', null, null, $scheduledStart, $scheduledEnd, $isDayOff, true, false);
        }

        // Checked in — a real fact, computed regardless of whether a
        // schedule exists for this date.
        if ($checkIn) {
            $lateMinutes = null;
            $status = 'Present';

            if ($scheduledStart && $checkIn->gt($scheduledStart->copy()->addMinutes(self::LATE_GRACE_MINUTES))) {
                $lateMinutes = (int) $scheduledStart->diffInMinutes($checkIn);
                $status = 'Late';
            }

            if (! $checkOut) {
                if ($this->dayHasEnded($date, $scheduledEnd, $now)) {
                    return $this->result('Incomplete', $lateMinutes, null, $scheduledStart, $scheduledEnd, $isDayOff, false, true);
                }

                return $this->result($status, $lateMinutes, null, $scheduledStart, $scheduledEnd, $isDayOff, false, false);
            }

            return $this->result($status, $lateMinutes, $this->workMinutes($checkIn, $checkOut, $schedule), $scheduledStart, $scheduledEnd, $isDayOff, false, false);
        }

        // No check-in at all. A day off, or a date with no schedule/branch
        // info to hold this staff member to, means nothing is expected of
        // them — never flagged (mirrors AppointmentAvailabilityService's
        // "not configured = open" stance).
        if ($isDayOff || (! $schedule && ! $branchSchedule)) {
            return $this->result(null, null, null, $scheduledStart, $scheduledEnd, $isDayOff, false, false);
        }

        // Only becomes Absent once the shift/day has actually passed —
        // never just because there's no check-in yet.
        if ($this->dayHasEnded($date, $scheduledEnd, $now)) {
            return $this->result('Absent', null, null, $scheduledStart, $scheduledEnd, $isDayOff, true, false);
        }

        return $this->result(null, null, null, $scheduledStart, $scheduledEnd, $isDayOff, false, false);
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

        if ($schedule && $schedule->break_start && $schedule->break_end) {
            $breakStart = Carbon::parse($checkIn->format('Y-m-d') . ' ' . $schedule->break_start);
            $breakEnd = Carbon::parse($checkIn->format('Y-m-d') . ' ' . $schedule->break_end);

            if ($checkIn->lte($breakStart) && $checkOut->gte($breakEnd)) {
                $minutes -= (int) $breakStart->diffInMinutes($breakEnd);
            }
        }

        return max(0, $minutes);
    }

    private function result(
        ?string $status,
        ?int $lateMinutes,
        ?int $workMinutes,
        ?Carbon $scheduledStart,
        ?Carbon $scheduledEnd,
        bool $isDayOff,
        bool $missingCheckIn,
        bool $missingCheckOut
    ): array {
        return [
            'status' => $status,
            'late_minutes' => $lateMinutes,
            'work_minutes' => $workMinutes,
            'scheduled_start' => $scheduledStart?->format('H:i'),
            'scheduled_end' => $scheduledEnd?->format('H:i'),
            'is_day_off' => $isDayOff,
            'missing_check_in' => $missingCheckIn,
            'missing_check_out' => $missingCheckOut,
        ];
    }
}
