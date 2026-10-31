<?php

namespace App\Service\Client;

use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\SpaBranch;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Service\Business\AppointmentAvailabilityService;
use App\Service\Business\HomeServiceRules;
use App\Service\PlanAccessService;
use App\Support\BranchClosures;
use App\Support\HourlyCapacity;
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
//    A requested therapist must also be on shift and not already booked;
//  - the hour has room: the owner's "bookings per hour" limit counts the
//    reservations whose start falls in the same clock hour (8:34 is in the
//    8 PM hour — App\Support\HourlyCapacity).
//
// Everything for the day is loaded once (a handful of queries) and checked
// in memory, so listing a whole day of slots costs the same as one check.
// A shift is what makes a therapist bookable: one with no shift set for the day
// (not set, or a day off) is not free then. A branch with no therapists on
// record at all isn't capacity-limited.
//
// A home service visit ($home) is only for staff the owner marked "Available
// for home service": the others are left out of the count, so "any therapist"
// means the home-service staff alone, and asking for one of the others is
// refused. In-spa bookings still waiting for someone take the other staff first.
class BookingSlotService
{
    // A start every five minutes: a client may book 8:35, not only 8:00 and 8:30.
    public const STEP_MINUTES = HourlyCapacity::STEP_MINUTES;

    // Used when the owner hasn't entered hours for that weekday.
    private const FALLBACK_OPEN = '09:00';

    private const FALLBACK_CLOSE = '21:00';

    public function __construct(private AppointmentAvailabilityService $availabilityService) {}

    // @return array{date: string, duration_minutes: int, closed_reason: ?string, slots: list<array{time: string, available: bool, reason: ?string}>, hours: list<array{hour: string, booked: int, limit: int, full: bool}>}
    public function slots(SpaBranch $branch, string $date, int $duration, ?Staff $therapist = null, ?int $excludeAppointmentId = null, bool $home = false): array
    {
        $duration = max($duration, 1);
        $policy = BookingPolicy::for($branch);
        $result = ['date' => $date, 'duration_minutes' => $duration, 'closed_reason' => null, 'slots' => [], 'hours' => []];

        if ($excludeAppointmentId === null) {
            $usage = app(PlanAccessService::class)->bookingUsage($branch->spa_business_id);
            if ($usage['limit'] !== null && $usage['used'] >= $usage['limit']) {
                $result['closed_reason'] = 'This spa has reached its app booking allowance for this month. Please contact the spa or try again next month.';

                return $result;
            }
        }
        $day = $this->dayWindow($branch->id, $date);
        if ($day['closed']) {
            $result['closed_reason'] = $day['reason'] ?? 'The spa is closed on this day.';

            return $result;
        }

        $dayProblem = BookingPolicy::startTimeProblem($policy, Carbon::parse($date)->endOfDay());
        if ($dayProblem && ! str_starts_with($dayProblem, 'Please book at least')) {
            $result['closed_reason'] = $dayProblem;

            return $result;
        }

        $ctx = $this->loadDay($branch, $date, $excludeAppointmentId, $home);
        $hourLimit = HourlyCapacity::limitFor($branch);
        $hourCounts = $hourLimit > 0 ? HourlyCapacity::bookedByHour($branch->id, $date, $excludeAppointmentId) : [];
        $hours = [];

        for ($start = $day['open']->copy(); $start->lt($day['close']) && $start->isSameDay($day['open']); $start->addMinutes(self::STEP_MINUTES)) {
            $end = $start->copy()->addMinutes($duration);

            // Times that can't be booked are still listed, as not available,
            // so the day reads as a whole: past or too soon, too late for the
            // services to finish by closing, or a closure in the way.
            $problem = match (true) {
                (bool) BookingPolicy::startTimeProblem($policy, $start) => 'too_soon',
                $end->gt($day['close']) => 'past_closing',
                (bool) $this->closureProblem($day, $start, $end) => 'closed',
                HourlyCapacity::isFull($hourLimit, $hourCounts[$start->hour] ?? 0) => 'hour_full',
                default => $this->capacityProblem($ctx, $date, $start, $end, $therapist),
            };
            $hours[] = $start->hour;
            $result['slots'][] = [
                'time' => $start->format('H:i'),
                'available' => $problem === null,
                'reason' => $problem,
            ];
        }

        $result['hours'] = HourlyCapacity::summary($hourLimit, $hourCounts, $hours);

        return $result;
    }

    // Why this exact booking can't be made, or null. Booking and reschedule
    // call this after the policy checks.
    public function bookingProblem(SpaBranch $branch, string $date, string $time, int $duration, ?Staff $therapist = null, ?int $excludeAppointmentId = null, bool $home = false): ?string
    {
        $duration = max($duration, 1);
        $day = $this->dayWindow($branch->id, $date);
        if ($day['closed']) {
            return $day['reason'] ?? 'The spa is closed on this day.';
        }

        $start = Carbon::parse("{$date} {$time}");
        $end = $start->copy()->addMinutes($duration);

        if ($start->lt($day['open']) || $start->gte($day['close'])) {
            return "That time is outside the spa's opening hours.";
        }
        if ($end->gt($day['close'])) {
            return 'Your services would run past closing time. Please pick an earlier time.';
        }
        if ($closed = $this->closureProblem($day, $start, $end)) {
            return $closed.' Please pick another time.';
        }

        // The hour's limit (and the 5-minute marks) — before the therapists,
        // it is the clearer reason when both apply.
        if ($hourProblem = HourlyCapacity::problemFor($branch, $date, $time, $excludeAppointmentId)) {
            return $hourProblem;
        }

        $problem = $this->capacityProblem($this->loadDay($branch, $date, $excludeAppointmentId, $home), $date, $start, $end, $therapist);

        return match ($problem) {
            null => null,
            'therapist_not_home' => HomeServiceRules::staffRefusal($therapist),
            'therapist_unavailable' => "{$this->nameOf($therapist)} isn't free at that time. Please pick another time or therapist.",
            default => 'That time just filled up. Please pick another time.',
        };
    }

    // For each therapist in $staffIds: the first start time after $time on that
    // day (in the same 5-minute steps the slots use) at which they are on shift
    // and not booked for $duration minutes, as H:i; null when there is none
    // left that day. Used to say "available at 5:00 PM" beside a therapist
    // who isn't free at the time asked for.
    //
    // @param  list<int>  $staffIds
    // @return array<int, ?string>
    public function nextFreeStarts(SpaBranch $branch, string $date, string $time, int $duration, array $staffIds): array
    {
        $duration = max($duration, 1);
        $result = array_fill_keys($staffIds, null);
        $day = $this->dayWindow($branch->id, $date);
        if ($day['closed'] || ! $staffIds) {
            return $result;
        }

        $policy = BookingPolicy::for($branch);
        $ctx = $this->loadDay($branch, $date, null);
        $hourLimit = HourlyCapacity::limitFor($branch);
        $hourCounts = $hourLimit > 0 ? HourlyCapacity::bookedByHour($branch->id, $date, null) : [];
        $left = $staffIds;

        for ($start = Carbon::parse("{$date} {$time}")->addMinutes(self::STEP_MINUTES); $left && $start->lt($day['close']) && $start->isSameDay($day['open']); $start->addMinutes(self::STEP_MINUTES)) {
            if ($start->lt($day['open'])) {
                continue;
            }
            $end = $start->copy()->addMinutes($duration);
            if ($end->gt($day['close'])) {
                break;
            }
            if (BookingPolicy::startTimeProblem($policy, $start)
                || $this->closureProblem($day, $start, $end)
                || HourlyCapacity::isFull($hourLimit, $hourCounts[$start->hour] ?? 0)) {
                continue;
            }

            $taken = $ctx['busy']
                ->filter(fn ($b) => $start->lt($b['end']) && $end->gt($b['start']))
                ->flatMap(fn ($b) => $b['staff'])->unique()->all();

            foreach ($left as $key => $id) {
                if ($this->onShift($ctx, $id, $date, $start, $end) && ! in_array($id, $taken, true)) {
                    $result[$id] = $start->format('H:i');
                    unset($left[$key]);
                }
            }
        }

        return $result;
    }

    // For each therapist in $staffIds: when they are free on $date for a visit
    // of $duration minutes, as stretches of time ("2:00 PM – 6:00 PM": a visit
    // can start anywhere from 2:00 and end by 6:00). Built from the same
    // 5-minute starts and the same checks as the slots (hours, booking rules,
    // closures, the hour's limit, their shift and bookings), so a free stretch
    // is always bookable. Times are H:i; an empty list when none is left.
    //
    // @param  list<int>  $staffIds
    // @return array<int, list<array{from: string, to: string}>>
    public function freeWindows(SpaBranch $branch, string $date, int $duration, array $staffIds): array
    {
        $duration = max($duration, 1);
        $result = array_fill_keys($staffIds, []);
        $day = $this->dayWindow($branch->id, $date);
        if ($day['closed'] || ! $staffIds) {
            return $result;
        }

        $policy = BookingPolicy::for($branch);
        $ctx = $this->loadDay($branch, $date, null);
        $hourLimit = HourlyCapacity::limitFor($branch);
        $hourCounts = $hourLimit > 0 ? HourlyCapacity::bookedByHour($branch->id, $date, null) : [];
        // The free stretch each therapist is in at the moment: [first start, last start].
        $open = [];
        $close = function (int $id) use (&$open, &$result, $duration) {
            if (isset($open[$id])) {
                [$first, $last] = $open[$id];
                $result[$id][] = ['from' => $first->format('H:i'), 'to' => $last->copy()->addMinutes($duration)->format('H:i')];
                unset($open[$id]);
            }
        };

        for ($start = $day['open']->copy(); $start->lt($day['close']) && $start->isSameDay($day['open']); $start->addMinutes(self::STEP_MINUTES)) {
            $end = $start->copy()->addMinutes($duration);
            $dayOk = ! $end->gt($day['close'])
                && ! BookingPolicy::startTimeProblem($policy, $start)
                && ! $this->closureProblem($day, $start, $end)
                && ! HourlyCapacity::isFull($hourLimit, $hourCounts[$start->hour] ?? 0);
            $taken = $dayOk
                ? $ctx['busy']->filter(fn ($b) => $start->lt($b['end']) && $end->gt($b['start']))->flatMap(fn ($b) => $b['staff'])->unique()->all()
                : [];

            foreach ($staffIds as $id) {
                if ($dayOk && $this->onShift($ctx, $id, $date, $start, $end) && ! in_array($id, $taken, true)) {
                    $open[$id] = [$open[$id][0] ?? $start->copy(), $start->copy()];
                } else {
                    $close($id);
                }
            }
        }
        foreach (array_keys($open) as $id) {
            $close($id);
        }

        return $result;
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

        // Temporary closures that touch this day's hours. One over the whole
        // working day reads as a closed day, with its reason.
        $closures = BranchClosures::overlapping($branchId, $open, $close);
        foreach ($closures as $closure) {
            if ($closure->starts_at->lte($open) && $closure->ends_at->gte($close)) {
                return ['closed' => true, 'reason' => BranchClosures::refusal($closure, 'spa')];
            }
        }

        return ['closed' => false, 'open' => $open, 'close' => $close, 'closures' => $closures];
    }

    // Why [$start, $end) can't be booked because of a temporary closure, or null.
    private function closureProblem(array $day, Carbon $start, Carbon $end): ?string
    {
        $closure = $day['closures']->first(fn ($c) => $start->lt($c->ends_at) && $end->gt($c->starts_at));

        return $closure ? BranchClosures::refusal($closure, 'spa') : null;
    }

    // Therapists, their shifts and leave, and the day's bookings as busy
    // windows (each tagged with the therapists assigned to it, if any).
    private function loadDay(SpaBranch $branch, string $date, ?int $excludeAppointmentId, bool $home = false): array
    {
        $all = Staff::where('spa_branch_id', $branch->id)
            ->where('role', 'therapist')
            ->where('status', 'active')
            ->get(array_merge(['id', 'uuid', 'first_name', 'last_name', 'role'], Staff::hasHomeService() ? ['home_service_available'] : []));
        // For a home visit only the staff who go out are counted; the rest
        // are kept apart, as they still take the in-spa bookings.
        $therapists = $home ? $all->filter(fn (Staff $s) => $s->takesHomeService())->values() : $all;
        $others = $home ? $all->reject(fn (Staff $s) => $s->takesHomeService())->values() : collect();
        $ids = $all->pluck('id')->all();

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
                    'home' => $a->isHomeService(),
                    'staff' => $a->services
                        ->flatMap(fn ($s) => $s->therapistAssignments->whereIn('assignment_status', ['Assigned', 'In Progress'])->pluck('staff_id'))
                        ->unique()->values()->all(),
                ];
            });

        return ['therapists' => $therapists, 'others' => $others, 'home' => $home, 'schedules' => $schedules, 'onLeave' => $onLeave, 'busy' => $busy];
    }

    // null = bookable; 'full' = no therapist left; 'therapist_unavailable'
    // = the requested therapist is off, on leave or already booked;
    // 'therapist_not_home' = a home visit, and they aren't marked for it.
    private function capacityProblem(array $ctx, string $date, Carbon $start, Carbon $end, ?Staff $therapist): ?string
    {
        /** @var Collection $therapists */
        $therapists = $ctx['therapists'];

        $overlapping = $ctx['busy']->filter(fn ($b) => $start->lt($b['end']) && $end->gt($b['start']));
        $takenStaff = $overlapping->flatMap(fn ($b) => $b['staff'])->unique()->all();
        $unassigned = $overlapping->filter(fn ($b) => empty($b['staff']))->count();

        if ($therapist) {
            if ($ctx['home'] && ! $therapist->takesHomeService()) {
                return 'therapist_not_home';
            }
            if (! $this->onShift($ctx, $therapist->id, $date, $start, $end) || in_array($therapist->id, $takenStaff, true)) {
                return 'therapist_unavailable';
            }
        }

        // No therapists on record: not capacity-limited (nothing to count). A
        // home visit with staff on record but none who go out has no one left.
        if ($therapists->isEmpty() && ! ($ctx['home'] && $ctx['others']->isNotEmpty())) {
            return null;
        }

        $free = $therapists
            ->filter(fn ($t) => $this->onShift($ctx, $t->id, $date, $start, $end) && ! in_array($t->id, $takenStaff, true))
            ->count();

        // Unassigned overlapping bookings each still need one of the free
        // therapists; this booking needs one more. For a home visit, the ones
        // in the spa go to the other staff first, and only what is left over
        // takes someone from the home-service staff.
        $needed = $unassigned;
        if ($ctx['home']) {
            $homeWaiting = $overlapping->filter(fn ($b) => empty($b['staff']) && $b['home'])->count();
            $othersFree = $ctx['others']
                ->filter(fn ($t) => $this->onShift($ctx, $t->id, $date, $start, $end) && ! in_array($t->id, $takenStaff, true))
                ->count();
            $needed = $homeWaiting + max(($unassigned - $homeWaiting) - $othersFree, 0);
        }

        return $free > $needed ? null : 'full';
    }

    private function onShift(array $ctx, int $staffId, string $date, Carbon $start, Carbon $end): bool
    {
        if (in_array($staffId, $ctx['onLeave'], true)) {
            return false;
        }

        $rows = $ctx['schedules']->get($staffId);
        if (! $rows || $rows->isEmpty()) {
            return false; // no shift set for the day
        }

        foreach ($rows as $row) {
            if ($row->is_day_off || ! $row->start_time || ! $row->end_time) {
                continue;
            }
            $shiftStart = Carbon::parse("{$date} {$row->start_time}");
            $shiftEnd = Carbon::parse("{$date} {$row->end_time}");
            // Ending at or before it starts (9 AM – 12 AM, or 10 PM – 2 AM): ends the next day.
            if ($shiftEnd->lte($shiftStart)) {
                $shiftEnd->addDay();
            }
            if ($start->lt($shiftStart) || $end->gt($shiftEnd)) {
                continue;
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
