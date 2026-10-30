<?php

namespace App\Service\Business;

use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Whether a staff member can be counted on for a booking on a given day, for the warnings the front
// desk is shown next to whoever is assigned, and for choosing someone when a reservation is given
// staff automatically. It reads the same attendance result as the rest of the app
// (AttendanceStatusCalculator), plus one thing it doesn't say: someone whose shift has started but
// who hasn't timed in yet ("Absent" only comes once the shift is over).
//
//   day_off   their schedule gives them the day off (any day), and they haven't come in anyway
//   on_leave  marked On Leave or Holiday today
//   absent    marked absent today, or the shift is over with no time-in
//   late      timed in late today, or the shift started more than the grace period ago and they haven't timed in
//
// Only today's attendance exists, so on_leave / absent / late are only ever reported for today; a day
// off is known ahead.
class StaffAttendanceWarning
{
    public function __construct(private AttendanceStatusCalculator $status)
    {
    }

    /**
     * @param  Collection<int, \App\Models\StaffSchedule>  $schedules  all of the staff member's schedule rows
     * @return array{kind: string, message: string, minutes: ?int}|null
     */
    public function check(Staff $staff, ?Attendance $attendance, string $date, Collection $schedules, ?BranchSchedule $branchSchedule, int $graceMinutes, ?Carbon $now = null): ?array
    {
        $now ??= now();
        $name = trim("{$staff->first_name} {$staff->last_name}") ?: 'This staff member';
        $isToday = $date === $now->format('Y-m-d');

        $result = $this->status->resolve($staff, $attendance, $date, $schedules, $branchSchedule, $now, $graceMinutes);

        $status = $result['status'] ?? null;

        // Marked by the desk for today: leave or absence says more than a day off does.
        if ($isToday && in_array($status, ['On Leave', 'Holiday'], true)) {
            return ['kind' => 'on_leave', 'message' => "{$name} is on leave today.", 'minutes' => null];
        }
        if ($isToday && $status === 'Absent') {
            return ['kind' => 'absent', 'message' => "{$name} is marked absent today.", 'minutes' => null];
        }
        if (! empty($result['is_day_off']) && ! $attendance?->check_in_at) {
            return ['kind' => 'day_off', 'message' => "{$name} is on their day off.", 'minutes' => null];
        }
        if (! $isToday) {
            return null;
        }
        if ($status === 'Late') {
            $minutes = isset($result['late_minutes']) ? (int) $result['late_minutes'] : null;

            return ['kind' => 'late', 'message' => "{$name} came in" . ($minutes ? " {$minutes} min" : '') . ' late today.', 'minutes' => $minutes];
        }

        // Nothing recorded yet and the shift has already started: late, or not coming.
        $start = $result['scheduled_start'] ?? null;
        if ($status === null && ! $attendance?->check_in_at && $start) {
            // "H:i" (or "H:i:s") on the day in question.
            $start = $start instanceof Carbon ? $start : Carbon::parse("{$date} {$start}");
            if ($now->gt($start->copy()->addMinutes($graceMinutes))) {
                $minutes = (int) $start->diffInMinutes($now);

                return ['kind' => 'late', 'message' => "{$name} hasn't timed in yet (their shift started at {$start->format('g:i A')}).", 'minutes' => $minutes];
            }
        }

        return null;
    }
}
