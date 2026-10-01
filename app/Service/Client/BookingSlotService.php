<?php

namespace App\Service\Client;

use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\SpaBranch;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Service\Business\AppointmentAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Which start times a client can actually book at a branch on one day, and
// the matching yes/no check booking and rescheduling run before saving.
//
// A time is bookable when:
//  - it's inside the branch's hours, the whole service ends by closing time
//    and doesn't run into the break;
//  - it respects the booking policy (online booking on, lead time, how far
//    ahead — see BookingPolicy);
//  - a therapist is left for it. Every overlapping booking takes one of the
//    therapists on shift then (schedule fits the whole window, not on leave),
//    so "any therapist" bookings can't pile onto a slot nobody can serve.
//    A requested therapist must also be on shift and not already booked.
//
// Everything for the day is loaded once (a handful of queries) and checked
// in memory, so listing a whole day of slots costs the same as one check.
// Same "not configured = open" stance as AppointmentAvailabilityService: a
// branch with no therapists on record isn't capacity-limited, and a
// therapist with no schedule rows works whenever the branch is open.
class BookingSlotService
{
    public const STEP_MINUTES = 30;

    // Used when the owner hasn't entered hours for that weekday.
    private const FALLBACK_OPEN = '09:00';
    private const FALLBACK_CLOSE = '21:00';

    public function __construct(private AppointmentAvailabilityService $availabilityService)
    {
    }

    // @return array{date: string, duration_minutes: int, closed_reason: ?string, slots: list<array{time: string, available: bool, reason: ?string}>}
    public function slots(SpaBranch $branch, string $date, int $duration, ?Staff $therapist = null, ?int $excludeAppointmentId = null): array
    {
        $duration = max($duration, 1);
        $policy = BookingPolicy::for($branch);
        $result = ['date' => $date, 'duration_minutes' => $duration, 'closed_reason' => null, 'slots' => []];

        $day = $this->dayWindow($branch->id, $date);
        if ($day['closed']) {
            $result['closed_reason'] = 'The spa is closed on this day.';

            return $result;
        }

        $dayProblem = BookingPolicy::startTimeProblem($policy, Carbon::parse($date)->endOfDay());
        if ($dayProblem && ! str_starts_with($dayProblem, 'Please book at least')) {
            $result['closed_reason'] = $dayProblem;

            return $result;
        }

        $ctx = $this->loadDay($branch, $date, $excludeAppointmentId);

        for ($start = $day['open']->copy(); $start->lt($day['close']) && $start->isSameDay($day['open']); $start->addMinutes(self::STEP_MINUTES)) {
            $end = $start->copy()->addMinutes($duration);

            if ($end->gt($day['close']) || $this->overlapsBreak($day, $start, $end)) {
                continue;
            }
            if (BookingPolicy::startTimeProblem($policy, $start)) {
                continue; // too soon / in the past — not offered at all
            }

            $problem = $this->capacityProblem($ctx, $date, $start, $end, $therapist);
            $result['slots'][] = [
                'time' => $start->format('H:i'),
                'available' => $problem === null,
                'reason' => $problem,
            ];
        }

        return $result;
    }

    // Why this exact booking can't be made, or null. Booking and reschedule
    // call this after the policy checks.
    public function bookingProblem(SpaBranch $branch, string $date, string $time, int $duration, ?Staff $therapist = null, ?int $excludeAppointmentId = null): ?string
    {
        $duration = max($duration, 1);
        $day = $this->dayWindow($branch->id, $date);
        if ($day['closed']) {
            return 'The spa is closed on this day.';
        }

        $start = Carbon::parse("{$date} {$time}");
        $end = $start->copy()->addMinutes($duration);

        if ($start->lt($day['open']) || $start->gte($day['close'])) {
            return "That time is outside the spa's opening hours.";
        }
        if ($end->gt($day['close'])) {
            return 'Your services would run past closing time. Please pick an earlier time.';
        }
        if ($this->overlapsBreak($day, $start, $end)) {
            return "That time runs into the spa's break. Please pick another time.";
        }

        $problem = $this->capacityProblem($this->loadDay($branch, $date, $excludeAppointmentId), $date, $start, $end, $therapist);

        return match ($problem) {
            null => null,
            'therapist_unavailable' => "{$this->nameOf($therapist)} isn't free at that time. Please pick another time or therapist.",
            default => 'That time just filled up. Please pick another time.',
        };
    }

    // ── internals ────────────────────────────────────────────────────────

    private function dayWindow(int $branchId, string $date): array
    {
        $schedule = BranchSchedule::where('spa_branch_id', $branchId)
            ->where('day_of_week', Carbon::parse($date)->format('l'))
            ->first();

        if ($schedule?->is_closed) {
            return ['closed' => true];
        }

        $openTime = $schedule?->opening_time ?: self::FALLBACK_OPEN;
        $closeTime = $schedule?->closing_time ?: self::FALLBACK_CLOSE;
        $open = Carbon::parse("{$date} {$openTime}");
        $close = Carbon::parse("{$date} {$closeTime}");

        // Same conventions as AppointmentAvailabilityService::branchIsOpen:
        // equal times = open all day; closing <= opening = closes next day.
        if ($close->lte($open)) {
            $close->addDay();
        }

        $break = null;
        if ($schedule?->break_start && $schedule?->break_end) {
            $breakStart = Carbon::parse("{$date} {$schedule->break_start}");
            $breakEnd = Carbon::parse("{$date} {$schedule->break_end}");
            if ($breakEnd->gt($breakStart)) {
                $break = [$breakStart, $breakEnd];
            }
        }

        return ['closed' => false, 'open' => $open, 'close' => $close, 'break' => $break];
    }

    private function overlapsBreak(array $day, Carbon $start, Carbon $end): bool
    {
        return $day['break'] && $start->lt($day['break'][1]) && $end->gt($day['break'][0]);
    }

    // Therapists, their shifts and leave, and the day's bookings as busy
    // windows (each tagged with the therapists assigned to it, if any).
    private function loadDay(SpaBranch $branch, string $date, ?int $excludeAppointmentId): array
    {
        $therapists = Staff::where('spa_branch_id', $branch->id)
            ->where('role', 'therapist')
            ->where('status', 'active')
            ->get(['id', 'uuid', 'first_name', 'last_name']);
        $ids = $therapists->pluck('id')->all();

        $dayOfWeek = Carbon::parse($date)->format('l');
        $schedules = StaffSchedule::whereIn('staff_id', $ids)
            ->where('day_of_week', $dayOfWeek)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->get()
            ->groupBy('staff_id');

        $onLeave = Attendance::whereIn('staff_id', $ids)
            ->whereDate('attendance_date', $date)
            ->where('status', 'On Leave')
            ->pluck('staff_id')
            ->all();

        $busy = Appointment::where('spa_branch_id', $branch->id)
            ->whereDate('appointment_date', $date)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW, Appointment::STATUS_COMPLETED])
            ->when($excludeAppointmentId, fn ($q) => $q->where('id', '!=', $excludeAppointmentId))
            ->with(['services.serviceVariant', 'services.therapistAssignments'])
            ->get()
            ->map(function (Appointment $a) use ($date) {
                $start = Carbon::parse("{$date} ".substr((string) $a->appointment_time, 0, 5));

                return [
                    'start' => $start,
                    'end' => $start->copy()->addMinutes(max($this->availabilityService->estimatedDurationMinutes($a), 30)),
                    'staff' => $a->services
                        ->flatMap(fn ($s) => $s->therapistAssignments->whereIn('assignment_status', ['Assigned', 'In Progress'])->pluck('staff_id'))
                        ->unique()->values()->all(),
                ];
            });

        return ['therapists' => $therapists, 'schedules' => $schedules, 'onLeave' => $onLeave, 'busy' => $busy];
    }

    // null = bookable; 'full' = no therapist left; 'therapist_unavailable'
    // = the requested therapist is off, on leave or already booked.
    private function capacityProblem(array $ctx, string $date, Carbon $start, Carbon $end, ?Staff $therapist): ?string
    {
        /** @var Collection $therapists */
        $therapists = $ctx['therapists'];

        $overlapping = $ctx['busy']->filter(fn ($b) => $start->lt($b['end']) && $end->gt($b['start']));
        $takenStaff = $overlapping->flatMap(fn ($b) => $b['staff'])->unique()->all();
        $unassigned = $overlapping->filter(fn ($b) => empty($b['staff']))->count();

        if ($therapist) {
            if (! $this->onShift($ctx, $therapist->id, $date, $start, $end) || in_array($therapist->id, $takenStaff, true)) {
                return 'therapist_unavailable';
            }
        }

        // No therapists on record: not capacity-limited (nothing to count).
        if ($therapists->isEmpty()) {
            return null;
        }

        $free = $therapists
            ->filter(fn ($t) => $this->onShift($ctx, $t->id, $date, $start, $end) && ! in_array($t->id, $takenStaff, true))
            ->count();

        // Unassigned overlapping bookings each still need one of the free
        // therapists; this booking needs one more.
        return $free > $unassigned ? null : 'full';
    }

    private function onShift(array $ctx, int $staffId, string $date, Carbon $start, Carbon $end): bool
    {
        if (in_array($staffId, $ctx['onLeave'], true)) {
            return false;
        }

        $rows = $ctx['schedules']->get($staffId);
        if (! $rows || $rows->isEmpty()) {
            return true; // no schedule entered — works whenever the branch is open
        }

        foreach ($rows as $row) {
            if ($row->is_day_off || ! $row->start_time || ! $row->end_time) {
                continue;
            }
            $shiftStart = Carbon::parse("{$date} {$row->start_time}");
            $shiftEnd = Carbon::parse("{$date} {$row->end_time}");
            if ($start->lt($shiftStart) || $end->gt($shiftEnd)) {
                continue;
            }
            if ($row->break_start && $row->break_end) {
                $breakStart = Carbon::parse("{$date} {$row->break_start}");
                $breakEnd = Carbon::parse("{$date} {$row->break_end}");
                if ($start->lt($breakEnd) && $end->gt($breakStart)) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    private function nameOf(?Staff $staff): string
    {
        return $staff ? (trim("{$staff->first_name} {$staff->last_name}") ?: 'Your therapist') : 'Your therapist';
    }
}
