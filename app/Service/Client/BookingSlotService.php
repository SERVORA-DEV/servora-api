<?php

namespace App\Service\Client;

use App\Enums\AppointmentStatus;
use App\Enums\AssignmentStatus;
use App\Enums\AttendanceStatus;
use App\Enums\FacilityStatus;
use App\Enums\StaffStatus;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\Facility;
use App\Models\SpaBranch;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\StaffTimeOff;
use App\Service\Business\AppointmentAvailabilityService;
use App\Service\Business\HomeServiceRules;
use App\Support\Branches\BranchClosures;
use App\Support\Branches\BranchOpenWindows;
use App\Support\Branches\HourlyCapacity;
use App\Support\Visits\RoomCapacity;
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
//    8 PM hour — App\Support\Branches\HourlyCapacity);
//  - a facility-based service (a sauna) has a facility free for it: one with the
//    service turned on, in rotation, with room for one more client.
//    A booking of only those needs no therapist at all (ServiceParts::staffFor).
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
    // $need: staff the booking needs at once (a service done by several staff; ServiceParts::needOf),
    // 0 for facility-based services only. $roomServices: its facility-based services (service ids,
    // ServiceParts::roomServices), each needing a facility free for it.
    public function slots(SpaBranch $branch, string $date, int $duration, ?Staff $therapist = null, ?int $excludeAppointmentId = null, bool $home = false, int $need = 1, array $roomServices = []): array
    {
        $duration = max($duration, 1);
        $policy = BookingPolicy::for($branch);
        $result = ['date' => $date, 'duration_minutes' => $duration, 'closed_reason' => null, 'slots' => [], 'hours' => []];

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

        $ctx = $this->loadDay($branch, $date, $excludeAppointmentId, $home, $roomServices !== []);
        $hourLimit = HourlyCapacity::limitFor($branch);
        $hourCounts = $hourLimit > 0 ? HourlyCapacity::bookedByHour($branch->id, $date, $excludeAppointmentId) : [];
        $hours = [];

        foreach ($this->startsOf($day) as ['start' => $start, 'close' => $closeAt]) {
            $end = $start->copy()->addMinutes($duration);

            // Times that can't be booked are still listed, as not available,
            // so the day reads as a whole: past or too soon, too late for the
            // services to finish by closing, or a closure in the way.
            $problem = match (true) {
                (bool) BookingPolicy::startTimeProblem($policy, $start) => 'too_soon',
                $end->gt($closeAt) => 'past_closing',
                (bool) $this->closureProblem($day, $start, $end) => 'closed',
                HourlyCapacity::isFull($hourLimit, $hourCounts[$start->hour] ?? 0) => 'hour_full',
                default => $this->capacityProblem($ctx, $date, $start, $end, $therapist, $need)
                    ?? $this->roomProblem($ctx, $start, $end, $roomServices),
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
    public function bookingProblem(SpaBranch $branch, string $date, string $time, int $duration, ?Staff $therapist = null, ?int $excludeAppointmentId = null, bool $home = false, int $need = 1, array $roomServices = []): ?string
    {
        $duration = max($duration, 1);
        $day = $this->dayWindow($branch->id, $date);
        if ($day['closed']) {
            return $day['reason'] ?? 'The spa is closed on this day.';
        }

        $start = Carbon::parse("{$date} {$time}");
        $end = $start->copy()->addMinutes($duration);

        // Inside one of the day's stretches: last night's hours running into this morning, or today's own.
        $window = collect($day['windows'])->first(fn ($w) => $start->gte($w['open']) && $start->lt($w['to']));
        if (! $window) {
            return "That time is outside the spa's opening hours.";
        }
        if ($end->gt($window['close'])) {
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

        $ctx = $this->loadDay($branch, $date, $excludeAppointmentId, $home, $roomServices !== []);
        $problem = $this->capacityProblem($ctx, $date, $start, $end, $therapist, $need)
            ?? $this->roomProblem($ctx, $start, $end, $roomServices);

        return match ($problem) {
            null => null,
            'room_full' => 'Every facility for that service is taken at that time. Please pick another time.',
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
    // $home: a home visit, which keeps its staff for the trip there and back too.
    public function nextFreeStarts(SpaBranch $branch, string $date, string $time, int $duration, array $staffIds, bool $home = false): array
    {
        $travel = $home ? \App\Service\Business\HomeServiceRules::travelMinutes($branch->id) : 0;
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

        $after = Carbon::parse("{$date} {$time}");

        foreach ($this->startsOf($day) as ['start' => $start, 'close' => $closeAt]) {
            if (! $left) {
                break;
            }
            if ($start->lte($after)) {
                continue;
            }
            $end = $start->copy()->addMinutes($duration);
            if ($end->gt($closeAt)) {
                continue;
            }
            if (BookingPolicy::startTimeProblem($policy, $start)
                || $this->closureProblem($day, $start, $end)
                || HourlyCapacity::isFull($hourLimit, $hourCounts[$start->hour] ?? 0)) {
                continue;
            }

            [$from, $to] = [$start->copy()->subMinutes($travel), $end->copy()->addMinutes($travel)];
            $taken = $ctx['busy']
                ->filter(fn ($b) => $from->lt($b['end']) && $to->gt($b['start']))
                ->flatMap(fn ($b) => $b['staff'])->unique()->all();

            foreach ($left as $key => $id) {
                if ($this->onShift($ctx, $id, $date, $from, $to) && ! in_array($id, $taken, true)) {
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
    // $home: a home visit, which keeps its staff for the trip there and back too.
    public function freeWindows(SpaBranch $branch, string $date, int $duration, array $staffIds, bool $home = false): array
    {
        $travel = $home ? \App\Service\Business\HomeServiceRules::travelMinutes($branch->id) : 0;
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

        foreach ($this->startsOf($day) as ['start' => $start, 'close' => $closeAt, 'first' => $first]) {
            // A stretch never runs on across the gap between last night's hours and today's own.
            if ($first) {
                foreach (array_keys($open) as $id) {
                    $close($id);
                }
            }
            $end = $start->copy()->addMinutes($duration);
            $dayOk = ! $end->gt($closeAt)
                && ! BookingPolicy::startTimeProblem($policy, $start)
                && ! $this->closureProblem($day, $start, $end)
                && ! HourlyCapacity::isFull($hourLimit, $hourCounts[$start->hour] ?? 0);
            [$from, $to] = [$start->copy()->subMinutes($travel), $end->copy()->addMinutes($travel)];
            $taken = $dayOk
                ? $ctx['busy']->filter(fn ($b) => $from->lt($b['end']) && $to->gt($b['start']))->flatMap(fn ($b) => $b['staff'])->unique()->all()
                : [];

            foreach ($staffIds as $id) {
                if ($dayOk && $this->onShift($ctx, $id, $date, $from, $to) && ! in_array($id, $taken, true)) {
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

    // The stretches of this date a client can book (App\Support\Branches\BranchOpenWindows): a branch open
    // until 3 AM is open 12:00 – 3:00 AM on the NEXT date, which is listed there, not on the night
    // it opened, and that next date is bookable for it even if the branch is closed that day.
    private function dayWindow(int $branchId, string $date): array
    {
        $dayName = Carbon::parse($date)->format('l');
        $before = Carbon::parse($date)->subDay()->format('l');
        $after = Carbon::parse($date)->addDay()->format('l');
        $rows = BranchSchedule::where('spa_branch_id', $branchId)
            ->whereIn('day_of_week', [$dayName, $before, $after])
            ->get()
            ->keyBy('day_of_week');

        // The next date too: a branch open round the clock runs on past midnight into it.
        $set = BranchOpenWindows::for(
            $rows->get($dayName),
            $rows->get($before),
            [BranchOpenWindows::minutes(self::FALLBACK_OPEN), BranchOpenWindows::minutes(self::FALLBACK_CLOSE)],
            $rows->get($after),
        );
        if ($set['closed']) {
            return ['closed' => true];
        }

        $midnight = Carbon::parse($date)->startOfDay();
        $windows = array_map(fn (array $w) => [
            'open' => $midnight->copy()->addMinutes($w['from']),
            'to' => $midnight->copy()->addMinutes($w['to']),
            'close' => $midnight->copy()->addMinutes($w['close']),
        ], $set['windows']);
        $open = $windows[0]['open'];
        $close = $windows[array_key_last($windows)]['close'];
        // The last start this date offers: a closure covering every start closes the day.
        $lastStart = $windows[array_key_last($windows)]['to'];

        // Temporary closures that touch this day's hours. One over the whole
        // working day reads as a closed day, with its reason.
        $closures = BranchClosures::overlapping($branchId, $open, $close);
        foreach ($closures as $closure) {
            if ($closure->starts_at->lte($open) && $closure->ends_at->gte($lastStart)) {
                return ['closed' => true, 'reason' => BranchClosures::refusal($closure, 'spa')];
            }
        }

        return ['closed' => false, 'open' => $open, 'close' => $close, 'windows' => $windows, 'closures' => $closures];
    }

    // Every start of the day in order, each with when its stretch really closes (past midnight for
    // tonight's hours) and whether it is the first of its stretch.
    private function startsOf(array $day): array
    {
        $starts = [];
        foreach ($day['windows'] as $window) {
            $first = true;
            for ($start = $window['open']->copy(); $start->lt($window['to']); $start->addMinutes(self::STEP_MINUTES)) {
                $starts[] = ['start' => $start->copy(), 'close' => $window['close'], 'first' => $first];
                $first = false;
            }
        }

        return $starts;
    }

    // Why [$start, $end) can't be booked because of a temporary closure, or null.
    private function closureProblem(array $day, Carbon $start, Carbon $end): ?string
    {
        $closure = $day['closures']->first(fn ($c) => $start->lt($c->ends_at) && $end->gt($c->starts_at));

        return $closure ? BranchClosures::refusal($closure, 'spa') : null;
    }

    // Which of these staff have a shift covering $time for $minutes on $date: the
    // same test the slots and bookingProblem() make (a day with no shift set is not
    // free, nor a day on leave; last night's shift running past midnight counts).
    // The client's therapist picker asks this, so it never offers someone a booking
    // would then refuse. Bookings aren't looked at here — only the shift.
    //
    // @param  list<int>  $staffIds
    // @return array<int, bool>
    public function onShiftFor(string $date, string $time, int $minutes, array $staffIds): array
    {
        if (! $staffIds) {
            return [];
        }
        $ctx = $this->shiftsOn($staffIds, $date);
        $start = Carbon::parse("{$date} ".substr($time, 0, 5));
        $end = $start->copy()->addMinutes(max($minutes, 1));

        return collect($staffIds)->mapWithKeys(fn ($id) => [$id => $this->onShift($ctx, $id, $date, $start, $end)])->all();
    }

    // The shift rows (today's, and last night's that may run into this morning)
    // and the leave that onShift() reads, for these staff on $date.
    //
    // @param  list<int>  $ids
    // @return array{schedules: Collection, prevSchedules: Collection, onLeave: list<int>}
    private function shiftsOn(array $ids, string $date): array
    {
        $dayOfWeek = Carbon::parse($date)->format('l');
        $schedules = StaffSchedule::whereIn('staff_id', $ids)
            ->where('day_of_week', $dayOfWeek)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->get()
            ->groupBy('staff_id');

        // A shift that ran past midnight last night (10 PM – 2 AM) is on this morning too.
        $before = Carbon::parse($date)->subDay()->toDateString();
        $prevSchedules = StaffSchedule::whereIn('staff_id', $ids)
            ->where('day_of_week', Carbon::parse($before)->format('l'))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $before))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $before))
            ->get()
            ->groupBy('staff_id');

        // Away for the day: marked "On leave" on the attendance roster, or time off
        // set on the employee (Employees → Time off) — the same two that
        // AppointmentAvailabilityService::leaveOn() refuses a booking for.
        $onLeave = Attendance::whereIn('staff_id', $ids)
            ->whereDate('attendance_date', $date)
            ->where('status', AttendanceStatus::OnLeave->value)
            ->pluck('staff_id');
        if (StaffTimeOff::ready()) {
            $onLeave = $onLeave->merge(StaffTimeOff::whereIn('staff_id', $ids)
                ->where('starts_on', '<=', $date)
                ->where('ends_on', '>=', $date)
                ->pluck('staff_id'));
        }
        $onLeave = $onLeave->map(fn ($id) => (int) $id)->unique()->values()->all();

        return ['schedules' => $schedules, 'prevSchedules' => $prevSchedules, 'onLeave' => $onLeave];
    }

    // Therapists, their shifts and leave, and the day's bookings as busy
    // windows (each tagged with the therapists assigned to it, if any).
    private function loadDay(SpaBranch $branch, string $date, ?int $excludeAppointmentId, bool $home = false, bool $withRooms = false): array
    {
        $all = Staff::where('spa_branch_id', $branch->id)
            ->where('role', 'therapist')
            ->where('status', StaffStatus::Active->value)
            ->get(array_merge(['id', 'uuid', 'first_name', 'last_name', 'role'], Staff::hasHomeService() ? ['home_service_available'] : []));
        // For a home visit only the staff who go out are counted; the rest
        // are kept apart, as they still take the in-spa bookings.
        $therapists = $home ? $all->filter(fn (Staff $s) => $s->takesHomeService())->values() : $all;
        $others = $home ? $all->reject(fn (Staff $s) => $s->takesHomeService())->values() : collect();
        $ids = $all->pluck('id')->all();

        ['schedules' => $schedules, 'prevSchedules' => $prevSchedules, 'onLeave' => $onLeave] = $this->shiftsOn($ids, $date);
        $before = Carbon::parse($date)->subDay()->toDateString();

        // Last night's bookings too: one that runs past midnight still holds its staff this morning.
        $busy = Appointment::where('spa_branch_id', $branch->id)
            ->where(fn ($q) => $q->whereDate('appointment_date', $date)->orWhereDate('appointment_date', $before))
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value, AppointmentStatus::Completed->value])
            ->when($excludeAppointmentId, fn ($q) => $q->where('id', '!=', $excludeAppointmentId))
            ->with(['services.serviceVariant', 'services.therapistAssignments'])
            ->get();
        // How many staff each booking needs at once (a service done by several staff).
        $needs = \App\Models\Service::staffNeededFor($busy->flatMap(fn (Appointment $a) => $a->services->map(fn ($s) => $s->serviceVariant?->service_id)));
        // A package bought for several clients holds the staff for those not added yet.
        $open = \App\Support\Catalog\SharedPackages::openSlotsFor($busy->pluck('id'));
        $kinds = \App\Models\Service::whereIn('id', $busy->flatMap(fn (Appointment $a) => $a->services->map(fn ($s) => $s->serviceVariant?->service_id))->filter()->unique()->values())
            ->pluck('service_type', 'id');
        $busy = $busy
            ->map(function (Appointment $a) use ($date, $needs, $open, $kinds) {
                // A home visit keeps its staff for the trip there and back too (HomeServiceRules).
                [$start, $end] = \App\Service\Business\HomeServiceRules::blockedWindow($a, max($this->availabilityService->estimatedDurationMinutes($a), 30));
                // Only facility-based services (a sauna): it holds no therapist.
                $need = $this->facilityOnly($a, $kinds)
                    ? 0
                    : max((int) $a->services->map(fn ($s) => $needs[$s->serviceVariant?->service_id] ?? 1)->push(1)->max(), 1 + ($open[$a->id] ?? 0));

                return [
                    'need' => $need,
                    'start' => $start,
                    'end' => $end,
                    'home' => $a->isHomeService(),
                    'staff' => $a->services
                        ->flatMap(fn ($s) => $s->therapistAssignments->whereIn('assignment_status', [AssignmentStatus::Assigned->value, AssignmentStatus::InProgress->value])->pluck('staff_id'))
                        ->filter()->unique()->values()->all(),
                    // The facilities it is in (a client each).
                    'rooms' => $a->services
                        ->flatMap(fn ($s) => $s->therapistAssignments->whereIn('assignment_status', [AssignmentStatus::Assigned->value, AssignmentStatus::InProgress->value])->pluck('facility_id'))
                        ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
                ];
            });

        // Staff on the way back from a home visit already done: busy until they are back.
        $dayStart = Carbon::parse($date)->startOfDay();
        foreach (\App\Service\Business\HomeServiceRules::returnTrips($all->pluck('id')->all(), $dayStart, $dayStart->copy()->endOfDay()) as $staffId => $trip) {
            $busy->push(['need' => 1, 'start' => $trip['from'], 'end' => $trip['to'], 'home' => true, 'staff' => [$staffId]]);
        }

        // A home visit being booked keeps its staff for the trip there and back too.
        $travel = $home ? \App\Service\Business\HomeServiceRules::travelMinutes($branch->id) : 0;

        return ['therapists' => $therapists, 'others' => $others, 'home' => $home, 'travel' => $travel, 'schedules' => $schedules, 'prevSchedules' => $prevSchedules, 'onLeave' => $onLeave, 'busy' => $busy,
            // Facilities only when a facility-based service is being booked (and never for a home visit).
            'rooms' => $withRooms && ! $home ? $this->loadRooms($branch) : collect()];
    }

    /** Every live line of the booking is a facility-based service (ServiceKind::needsTherapist). */
    private function facilityOnly(Appointment $a, Collection $kinds): bool
    {
        $ids = $a->services
            ->reject(fn ($s) => $s->status === \App\Enums\AppointmentServiceStatus::Cancelled->value)
            ->map(fn ($s) => $s->serviceVariant?->service_id)
            ->filter();

        return $ids->isNotEmpty() && $ids->every(fn ($id) => ! \App\Support\Catalog\ServiceKind::needsTherapist($kinds[$id] ?? null));
    }

    // The branch's facilities a booking could be put in (in rotation, not under maintenance), with
    // how many clients each holds and the services it has turned on.
    private function loadRooms(SpaBranch $branch): Collection
    {
        $rooms = Facility::with('services:id')
            ->where('spa_branch_id', $branch->id)
            ->where('is_available', true)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', FacilityStatus::Maintenance->value))
            ->get();

        return $rooms->map(fn (Facility $f) => [
            'id' => $f->id,
            'limit' => $f->clientLimit(),
            'services' => $f->services->pluck('id')->all(),
        ]);
    }

    // 'room_full' when a facility-based service in the booking ($roomServices, service ids) has no
    // facility free for it then: one that has the service turned on (FacilityCoverage), is in use
    // and has room for one more client. None at all (or all under maintenance): no time is free.
    private function roomProblem(array $ctx, Carbon $start, Carbon $end, array $roomServices): ?string
    {
        if (! $roomServices) {
            return null;
        }
        $rooms = $ctx['rooms'] ?? collect();
        $overlapping = $ctx['busy']->filter(fn ($b) => ! empty($b['rooms']) && $start->lt($b['end']) && $end->gt($b['start']));

        foreach ($roomServices as $serviceId) {
            $suitable = $rooms->filter(fn ($r) => in_array($serviceId, $r['services'], true));
            if ($suitable->isEmpty()) {
                return 'room_full';
            }
            $free = $suitable->contains(function ($r) use ($overlapping, $start, $end) {
                $windows = $overlapping
                    ->filter(fn ($b) => in_array($r['id'], $b['rooms'], true))
                    ->map(fn ($b) => [max($b['start']->getTimestamp(), $start->getTimestamp()), min($b['end']->getTimestamp(), $end->getTimestamp())])
                    ->values()->all();

                return RoomCapacity::hasSpace(RoomCapacity::peak($windows), $r['limit']);
            });
            if (! $free) {
                return 'room_full';
            }
        }

        return null;
    }

    // null = bookable; 'full' = no therapist left; 'therapist_unavailable'
    // = the requested therapist is off, on leave or already booked;
    // 'therapist_not_home' = a home visit, and they aren't marked for it.
    private function capacityProblem(array $ctx, string $date, Carbon $start, Carbon $end, ?Staff $therapist, int $need = 1): ?string
    {
        /** @var Collection $therapists */
        $therapists = $ctx['therapists'];
        // A home visit keeps its staff from the travel time before it to the travel time after it.
        $start = $start->copy()->subMinutes($ctx['travel'] ?? 0);
        $end = $end->copy()->addMinutes($ctx['travel'] ?? 0);

        // Only facility-based services (a sauna): nobody is assigned, so the therapists don't limit it.
        if ($need <= 0) {
            return null;
        }

        $overlapping = $ctx['busy']->filter(fn ($b) => $start->lt($b['end']) && $end->gt($b['start']));
        $takenStaff = $overlapping->flatMap(fn ($b) => $b['staff'])->unique()->all();
        // What the overlapping bookings still need beyond who is on them: one each for a booking
        // nobody is on yet, more for a service done by several staff.
        $owed = fn ($b) => max(0, ($b['need'] ?? 1) - count($b['staff']));
        $unassigned = $overlapping->sum($owed);

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
            $homeWaiting = $overlapping->filter(fn ($b) => $b['home'])->sum($owed);
            $othersFree = $ctx['others']
                ->filter(fn ($t) => $this->onShift($ctx, $t->id, $date, $start, $end) && ! in_array($t->id, $takenStaff, true))
                ->count();
            $needed = $homeWaiting + max(($unassigned - $homeWaiting) - $othersFree, 0);
        }

        // This booking needs $need of them (more than one for a service done by several staff).
        return $free >= $needed + $need ? null : 'full';
    }

    private function onShift(array $ctx, int $staffId, string $date, Carbon $start, Carbon $end): bool
    {
        if (in_array($staffId, $ctx['onLeave'], true)) {
            return false;
        }

        $rows = $ctx['schedules']->get($staffId) ?? collect();
        $last = ($ctx['prevSchedules'] ?? collect())->get($staffId) ?? collect();
        if ($rows->isEmpty() && $last->isEmpty()) {
            return false; // no shift set for the day
        }

        // Today's shifts, and last night's that ran into this morning.
        $before = Carbon::parse($date)->subDay()->toDateString();
        foreach ([[$date, $rows], [$before, $last]] as [$on, $set]) {
            foreach ($set as $row) {
                if ($row->is_day_off || ! $row->start_time || ! $row->end_time) {
                    continue;
                }
                $shiftStart = Carbon::parse("{$on} {$row->start_time}");
                $shiftEnd = Carbon::parse("{$on} {$row->end_time}");
                // Ending at or before it starts (9 AM – 12 AM, or 10 PM – 2 AM): ends the next day.
                if ($shiftEnd->lte($shiftStart)) {
                    $shiftEnd->addDay();
                }
                if ($start->lt($shiftStart) || $end->gt($shiftEnd)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    private function nameOf(?Staff $staff): string
    {
        return $staff ? (trim("{$staff->first_name} {$staff->last_name}") ?: 'Your therapist') : 'Your therapist';
    }
}
