<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\BranchSchedule;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\TherapistAssignment;
use App\Repository\Business\StaffServiceRepository;
use App\Support\BranchClosures;
use App\Support\ReservationHold;
use App\Support\StaleVisit;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Every method returns ['ok' => bool, 'reason' => ?string] rather than
// throwing, matching this codebase's convention of surfacing domain errors
// as data the Service layer turns into a 422 (see AppointmentService).
//
// There is no per-service scheduled sub-slot in this schema — only one
// appointment_date/appointment_time per appointment, and this is a
// walk-in/queue-driven spa, not a fixed-slot scheduler: real availability
// depends on when the current service actually finishes and where a client
// sits in the queue, neither of which a projected time-window can predict.
// So isStaffAvailable() (below) does NOT gate reservations made by staff —
// AppointmentService's assign*/reschedule methods let them reserve a
// therapist ahead of time unconditionally, and suggestTherapists() treats
// an estimated overlap as a soft ranking signal. Client self-booking is the
// one exception on the staff side: createClientAppointment() hard-rejects a
// requested therapist this method says is busy, because the mobile app's
// therapist picker was filtered by the very same check (see
// SpaBranchService::publicTherapistAvailability) and a client has no way to
// renegotiate a shift the way a front officer does. Rooms are the other: a physical room
// genuinely can't hold two appointments at once, so isFacilityAvailable()
// (below) IS enforced as a hard gate in AppointmentService's room-assignment
// methods, unlike its staff counterpart. Beyond that, the one real
// real-time conflict check left in the system is the pair
// isStaffCurrentlyBusy()/isFacilityCurrentlyOccupied(), enforced once, at
// startService() — a service can't start on a resource that's genuinely In
// Progress elsewhere right now.
class AppointmentAvailabilityService
{
    private StaffServiceRepository $staffServiceRepository;

    public function __construct(StaffServiceRepository $staffServiceRepository)
    {
        $this->staffServiceRepository = $staffServiceRepository;
    }

    public function isStaffQualified(int $staffId, int $serviceId): array
    {
        if ($this->staffServiceRepository->isQualified($staffId, $serviceId)) {
            return ['ok' => true, 'reason' => null];
        }

        return ['ok' => false, 'reason' => 'This staff member is not qualified to perform this service.'];
    }

    // First real consumer of staff_schedules in this codebase. A staff
    // member with no schedule rows at all for the day is treated as
    // available (opt-in-if-configured, same stance as qualification) —
    // most branches won't have shift data entered before this module goes
    // live.
    public function staffMatchesSchedule(int $staffId, string $date, string $startTime, int $durationMinutes): array
    {
        $dayOfWeek = Carbon::parse($date)->format('l');
        $start = Carbon::parse("{$date} {$startTime}");
        $end = (clone $start)->addMinutes($durationMinutes);

        $schedules = StaffSchedule::where('staff_id', $staffId)
            ->where('day_of_week', $dayOfWeek)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->get();

        if ($schedules->isEmpty()) {
            return ['ok' => true, 'reason' => null];
        }

        $sawDayOff = false;

        foreach ($schedules as $schedule) {
            if ($schedule->is_day_off) {
                $sawDayOff = true;
                continue;
            }

            if (! $schedule->start_time || ! $schedule->end_time) {
                continue;
            }

            $shiftStart = Carbon::parse("{$date} {$schedule->start_time}");
            $shiftEnd = Carbon::parse("{$date} {$schedule->end_time}");
            // Ending at or before it starts (9 AM – 12 AM, or 10 PM – 2 AM): ends the next day.
            if ($shiftEnd->lte($shiftStart)) {
                $shiftEnd->addDay();
            }

            if ($start->lt($shiftStart) || $end->gt($shiftEnd)) {
                continue;
            }

            return ['ok' => true, 'reason' => null];
        }

        // Distinguishes "explicitly not working today" from "just doesn't fit
        // this window" — AppointmentService::therapistOptions() relies on
        // this same day-off signal (via AttendanceStatusCalculator) to hard-
        // block picking a day-off therapist even if they're checked in; this
        // gives the explicit Assign action (assignTherapistInternal) the
        // matching, clearer rejection message rather than the generic one.
        return [
            'ok' => false,
            'reason' => $sawDayOff
                ? 'This staff member is on their day off today and cannot be assigned.'
                : 'This staff member is not scheduled to work during the requested time.',
        ];
    }

    // The dates in [$from, $to] on which this therapist can't be booked — so
    // the client booking calendar can grey them out instead of letting a client
    // pick one and be told afterwards. Same day_of_week and effective_from/until
    // filtering as the slots (BookingSlotService), and a day is "off" unless it
    // has a usable shift: a day with no shift set is off too, and a therapist
    // with no schedule at all is off every day.
    //
    // Loads the staff member's rows once and walks the dates in PHP, rather
    // than one query per day across a two-month calendar.
    //
    // @return list<string> Y-m-d dates, ascending
    public function staffDaysOff(int $staffId, string $from, string $to): array
    {
        $rows = StaffSchedule::where('staff_id', $staffId)->get();

        $daysOff = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $last = Carbon::parse($to)->startOfDay();

        for (; $cursor->lte($last); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $dayOfWeek = $cursor->format('l');

            $schedules = $rows->filter(fn ($row) => $row->day_of_week === $dayOfWeek
                && (! $row->effective_from || Carbon::parse($row->effective_from)->toDateString() <= $date)
                && (! $row->effective_until || Carbon::parse($row->effective_until)->toDateString() >= $date));

            $hasShift = $schedules->contains(
                fn ($row) => ! $row->is_day_off && $row->start_time && $row->end_time
            );

            if (! $hasShift) {
                $daysOff[] = $date;
            }
        }

        return $daysOff;
    }

    // Branch-hours equivalent of staffMatchesSchedule() above — gates
    // whether the appointment itself can be booked at all, independent of
    // which staff/service is involved, so it's checked once per
    // appointment rather than per therapist. Same "not configured = open"
    // stance as staff schedules: a branch with no schedule row for that
    // day, or a row with either time left blank, is treated as open rather
    // than rejecting bookings for data the owner hasn't entered yet.
    //
    // There is no separate "open 24 hours" flag in the schema — two
    // equal same-day times (e.g. 00:00-00:00) is the only way to express
    // it, so that's treated as open all day. Beyond that, this follows
    // BranchScheduleRequest's documented convention: closing_time <=
    // opening_time means the branch closes the following day (e.g. open
    // 09:00, close 00:00 = closes at midnight; open 22:00, close 02:00 =
    // open past midnight) rather than being an invalid/never-open range.
    //
    // A temporary closure (a holiday, repairs) comes first: nothing starts
    // inside one, and nothing runs into one. $durationMinutes is how long
    // the visit is, when the caller knows; a closure is only asked for it
    // when there is a closure near that time to compare against.
    //
    // A visit also has to be over by closing time: nothing is booked (or
    // walked in) that would still be running when the branch closes. That is
    // checked when the length is known.
    public function branchIsOpen(int $branchId, string $date, string $startTime, int|\Closure|null $durationMinutes = null): array
    {
        $start = Carbon::parse("{$date} {$startTime}");
        $nearby = BranchClosures::overlapping($branchId, $start, $start->copy()->addDay());
        if ($nearby->isNotEmpty()) {
            $minutes = $durationMinutes instanceof \Closure ? $durationMinutes() : $durationMinutes;
            $end = $start->copy()->addMinutes(max((int) $minutes, 1));
            $closure = $nearby->first(fn ($c) => $c->starts_at->lt($end) && $c->ends_at->gt($start));
            if ($closure) {
                return ['ok' => false, 'reason' => BranchClosures::refusal($closure)];
            }
        }

        $dayOfWeek = Carbon::parse($date)->format('l');

        $schedule = BranchSchedule::where('spa_branch_id', $branchId)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if (! $schedule) {
            return ['ok' => true, 'reason' => null];
        }

        if ($schedule->is_closed) {
            return ['ok' => false, 'reason' => 'The branch is closed on this day.'];
        }

        if (! $schedule->opening_time || ! $schedule->closing_time) {
            return ['ok' => true, 'reason' => null];
        }

        $open = Carbon::parse("{$date} {$schedule->opening_time}");
        $close = Carbon::parse("{$date} {$schedule->closing_time}");

        if ($open->eq($close)) {
            return ['ok' => true, 'reason' => null];
        }

        $time = Carbon::parse("{$date} {$startTime}");

        $inWindow = $close->gt($open)
            ? $time->gte($open) && $time->lt($close)
            : $time->gte($open) || $time->lt($close);

        if (! $inWindow) {
            return ['ok' => false, 'reason' => 'The requested time is outside the branch\'s operating hours.'];
        }

        // When this opening's hours end: the same day, or the next for a branch open
        // past midnight (from the evening before, for a time after midnight).
        $endsAt = $close->gt($open) ? $close : ($time->gte($open) ? $close->copy()->addDay() : $close);
        $minutes = $durationMinutes instanceof \Closure ? $durationMinutes() : $durationMinutes;
        if ($minutes && $time->copy()->addMinutes((int) $minutes)->gt($endsAt)) {
            $latest = $endsAt->copy()->subMinutes((int) $minutes);
            $opensFrom = $close->gt($open) || $time->gte($open) ? $open : $open->copy()->subDay();

            return ['ok' => false, 'reason' => "This would still be running when the branch closes at {$endsAt->format('g:i A')}. "
                . ($latest->gte($opensFrom)
                    ? "For this visit the latest start is {$latest->format('g:i A')}."
                    : 'Choose fewer services, or another day.')];
        }

        return ['ok' => true, 'reason' => null];
    }

    // Soft signal for staff-made bookings (suggestTherapists() ranks
    // candidates with it and assign*() ignores it), but a hard gate for
    // client self-booking — see the class doc comment.
    // $excludeAppointmentId skips the appointment being moved, so a client
    // rescheduling within (or next to) their own slot isn't blocked by it.
    public function isStaffAvailable(int $staffId, string $date, string $startTime, int $durationMinutes, ?int $excludeAppointmentId = null): array
    {
        $start = Carbon::parse("{$date} {$startTime}");
        $end = (clone $start)->addMinutes($durationMinutes);

        $assignments = TherapistAssignment::with('appointmentService.appointment.services.serviceVariant')
            ->where('staff_id', $staffId)
            ->whereIn('assignment_status', ['Assigned', 'In Progress'])
            ->get();

        foreach ($assignments as $assignment) {
            if ($excludeAppointmentId && $assignment->appointmentService?->appointment_id === $excludeAppointmentId) {
                continue;
            }

            if ($this->overlapsAppointment($assignment, $date, $start, $end)) {
                return ['ok' => false, 'reason' => 'This staff member is already booked during the requested time.'];
            }
        }

        return ['ok' => true, 'reason' => null];
    }

    // Backs the front-office time picker's "grey out already-booked times."
    // Branch-wide rather than per-therapist: a therapist is optional at
    // booking time and usually assigned later at check-in, so most
    // appointments have no TherapistAssignment yet to key a per-staff check
    // off of — branch-wide is what actually catches the common case. Uses
    // the same appointment-total-duration math as overlapsAppointment() (no
    // per-service sub-slot in this schema), falling back to a 30-minute
    // window for an appointment with no services yet so it still occupies a
    // real slot instead of collapsing to zero width.
    public function busyWindowsForBranch(int $branchId, string $date): array
    {
        $appointments = Appointment::where('spa_branch_id', $branchId)
            ->where('appointment_date', $date)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW, Appointment::STATUS_COMPLETED])
            ->with('services.serviceVariant')
            ->get();

        return $appointments->map(function (Appointment $appointment) {
            $start = Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->appointment_time);
            $duration = max($this->estimatedDurationMinutes($appointment), 30);
            $end = (clone $start)->addMinutes($duration);
            return ['start' => $start->format('H:i'), 'end' => $end->format('H:i')];
        })->values()->all();
    }

    // Real-time check (not schedule-window based) — used at startService()
    // time to catch drift when an earlier service on this staff member ran
    // long past its estimated duration.
    // $exceptServiceIds: services this therapist may be doing alongside — the
    // same client's other procedure-based services (ServiceKind).
    public function isStaffCurrentlyBusy(int $staffId, ?int $excludeAssignmentId = null, array $exceptServiceIds = []): array
    {
        $busy = TherapistAssignment::where('staff_id', $staffId)
            ->where('assignment_status', 'In Progress')
            // Not one left running from a day that is over (StaleVisit): it would hold the person up for good.
            ->whereHas('appointmentService.appointment', fn ($q) => $q->where('appointment_date', '>=', StaleVisit::today()))
            ->when($excludeAssignmentId, fn ($q) => $q->where('id', '!=', $excludeAssignmentId))
            ->when($exceptServiceIds, fn ($q) => $q->whereNotIn('appointment_service_id', $exceptServiceIds))
            ->exists();

        return $busy
            ? ['ok' => false, 'reason' => 'This staff member is currently in another service.']
            : ['ok' => true, 'reason' => null];
    }

    // Held for a reservation (App\Support\ReservationHold): a staff member
    // assigned to another client's reservation today that hasn't checked in
    // yet can't start a service now that would still be running at its booked
    // time — until that reservation is cancelled (or marked a no-show) or its
    // grace period is over. A walk-in is never "reserved". Returns why, or null.
    public function reservedElsewhere(int $staffId, int $exceptAppointmentId, Carbon $now, int $lengthMinutes, int $graceMinutes): ?string
    {
        $held = TherapistAssignment::with('appointmentService.appointment.client', 'staff')
            ->where('staff_id', $staffId)
            ->where('assignment_status', 'Assigned')
            ->whereHas('appointmentService.appointment', fn ($q) => $q
                ->where('id', '!=', $exceptAppointmentId)
                ->where('status', Appointment::STATUS_SCHEDULED)
                ->where('appointment_type', '!=', 'Walk-in')
                ->whereDate('appointment_date', $now->toDateString()))
            ->get();

        foreach ($held as $assignment) {
            $reservation = $assignment->appointmentService?->appointment;
            if (! $reservation) {
                continue;
            }
            $bookedStart = Carbon::parse($reservation->appointment_date->format('Y-m-d') . ' ' . $reservation->appointment_time);
            if (! ReservationHold::blocks($bookedStart, $graceMinutes, $now, $lengthMinutes)) {
                continue;
            }

            $who = $assignment->staffName() ?? 'This staff member';
            $client = trim(($reservation->client?->first_name ?? '') . ' ' . ($reservation->client?->last_name ?? '')) ?: 'a client';
            $until = ReservationHold::endsAt($bookedStart, $graceMinutes)->format('g:i A');

            return "{$who} is reserved for {$client} at {$bookedStart->format('g:i A')} (held until {$until}).";
        }

        return null;
    }

    // Who is first with a staff member: a reservation that is already here
    // (checked in) and booked with them EARLIER than $mine is served before it —
    // a client who came early for their own booking waits for that one, or for
    // their booked time. Walk-ins keep to the queue's order. Returns why, or null.
    /**
     * Whether queueing an early reservation from $from (instead of its booked
     * time) would put it ahead of another client's booking with the same staff:
     * one booked after $from but before this one, not served yet. Says who,
     * or null when moving up takes no one's turn.
     */
    public function aheadOfBooking(Appointment $mine, \Carbon\CarbonInterface $from): ?string
    {
        $myStart = Carbon::parse($mine->appointment_date->format('Y-m-d') . ' ' . $mine->appointment_time);
        $staffIds = TherapistAssignment::where('assignment_status', 'Assigned')
            ->whereNotNull('staff_id')
            ->whereHas('appointmentService', fn ($q) => $q->where('appointment_id', $mine->id)->where('status', 'Pending'))
            ->pluck('staff_id')->unique()->all();
        if (! $staffIds) {
            return null;
        }

        $other = TherapistAssignment::with('appointmentService.appointment.client', 'staff')
            ->whereIn('staff_id', $staffIds)
            ->where('assignment_status', 'Assigned')
            ->whereHas('appointmentService', fn ($q) => $q->where('status', 'Pending'))
            ->whereHas('appointmentService.appointment', fn ($q) => $q
                ->where('id', '!=', $mine->id)
                ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CHECKED_IN])
                ->where('appointment_type', '!=', 'Walk-in')
                ->whereDate('appointment_date', $mine->appointment_date->toDateString()))
            ->get()
            ->map(fn (TherapistAssignment $a) => [$a, Carbon::parse($a->appointmentService->appointment->appointment_date->format('Y-m-d') . ' ' . $a->appointmentService->appointment->appointment_time)])
            ->filter(fn (array $pair) => $pair[1]->gte($from) && $pair[1]->lt($myStart))
            ->sortBy(fn (array $pair) => $pair[1]->getTimestamp())
            ->first();
        if (! $other) {
            return null;
        }

        [$assignment, $start] = $other;
        $client = $assignment->appointmentService->appointment->client;
        $name = trim(($client?->first_name ?? '') . ' ' . ($client?->last_name ?? '')) ?: 'another client';

        return ($assignment->staffName() ?? 'Their staff') . " has {$name} booked at {$start->format('g:i A')}, so this client keeps their booked place.";
    }

    public function bookedFirst(int $staffId, Appointment $mine): ?string
    {
        if ($mine->appointment_type === 'Walk-in') {
            return null;
        }
        $myStart = Carbon::parse($mine->appointment_date->format('Y-m-d') . ' ' . $mine->appointment_time);

        $earlier = TherapistAssignment::with('appointmentService.appointment.client', 'staff')
            ->where('staff_id', $staffId)
            ->where('assignment_status', 'Assigned')
            ->whereHas('appointmentService', fn ($q) => $q->where('status', 'Pending'))
            ->whereHas('appointmentService.appointment', fn ($q) => $q
                ->where('id', '!=', $mine->id)
                ->where('status', Appointment::STATUS_CHECKED_IN)
                ->where('appointment_type', '!=', 'Walk-in')
                ->whereDate('appointment_date', $mine->appointment_date->toDateString()))
            ->get()
            ->map(fn (TherapistAssignment $a) => [$a, Carbon::parse($a->appointmentService->appointment->appointment_date->format('Y-m-d') . ' ' . $a->appointmentService->appointment->appointment_time)])
            ->filter(fn (array $pair) => $pair[1]->lt($myStart))
            ->sortBy(fn (array $pair) => $pair[1]->getTimestamp())
            ->first();
        if (! $earlier) {
            return null;
        }

        [$assignment, $start] = $earlier;
        $client = $assignment->appointmentService->appointment->client;
        $name = trim(($client?->first_name ?? '') . ' ' . ($client?->last_name ?? '')) ?: 'a client';
        $who = $assignment->staffName() ?? 'This staff member';

        return "{$who} has {$name} first — booked at {$start->format('g:i A')} and already here. This client waits for them, or for their own booked time.";
    }

    // Someone ahead in the line is waiting for the same staff member (or
    // facility): a client further back can't take them first. "Ahead" is the
    // queue's own order (Queue::lineTime), walk-ins first when the branch
    // prioritises them. Returns why, or null when nobody ahead needs them.
    public function aheadInLine(?int $staffId, ?int $facilityId, Appointment $mine, bool $walkInsFirst = false): ?string
    {
        $mine->loadMissing('queue');
        if (! $mine->queue || (! $staffId && ! $facilityId)) {
            return null;
        }
        $myAt = $mine->queue->lineTime();
        $iAmWalkIn = $mine->appointment_type === 'Walk-in';

        $rows = TherapistAssignment::with('appointmentService.appointment.queue', 'appointmentService.appointment.client', 'staff', 'facility')
            ->where('assignment_status', 'Assigned')
            ->where(fn ($q) => $q
                ->when($staffId, fn ($w) => $w->orWhere('staff_id', $staffId))
                ->when($facilityId, fn ($w) => $w->orWhere('facility_id', $facilityId)))
            ->whereHas('appointmentService', fn ($q) => $q->where('status', 'Pending'))
            ->whereHas('appointmentService.appointment', fn ($q) => $q
                ->where('id', '!=', $mine->id)
                ->where('spa_branch_id', $mine->spa_branch_id)
                ->whereDate('appointment_date', $mine->appointment_date->toDateString())
                ->whereHas('queue', fn ($qq) => $qq->whereIn('queue_status', ['Waiting', 'Called'])))
            ->get();

        $ahead = $rows->filter(function (TherapistAssignment $a) use ($myAt, $iAmWalkIn, $walkInsFirst) {
            $other = $a->appointmentService->appointment;
            $theyWalkIn = $other->appointment_type === 'Walk-in';
            if ($walkInsFirst && $theyWalkIn !== $iAmWalkIn) {
                return $theyWalkIn;
            }

            return $other->queue->lineTime()->lt($myAt);
        })->sortBy(fn (TherapistAssignment $a) => $a->appointmentService->appointment->queue->lineTime()->getTimestamp())->first();
        if (! $ahead) {
            return null;
        }

        $other = $ahead->appointmentService->appointment;
        $client = trim(($other->client?->first_name ?? '') . ' ' . ($other->client?->last_name ?? '')) ?: 'a client';
        $ticket = $other->queue->queue_number ? " (ticket {$other->queue->queue_number})" : '';

        return $staffId && $ahead->staff_id === $staffId
            ? ($ahead->staffName() ?? 'This staff member') . " is next with {$client}{$ticket}, who is ahead in line."
            : ($ahead->roomName() ?? 'This facility') . " is next for {$client}{$ticket}, who is ahead in line.";
    }

    // $excludeAppointmentId: the visit a service is being started for — its
    // own service already running in the room isn't "someone else" (whether
    // the two may run together is App\Support\ServiceStartRules' call).
    public function isFacilityCurrentlyOccupied(int $facilityId, ?int $excludeAssignmentId = null, ?int $excludeAppointmentId = null): array
    {
        $occupied = TherapistAssignment::where('facility_id', $facilityId)
            ->where('assignment_status', 'In Progress')
            ->whereHas('appointmentService.appointment', fn ($q) => $q->where('appointment_date', '>=', StaleVisit::today()))
            ->when($excludeAssignmentId, fn ($q) => $q->where('id', '!=', $excludeAssignmentId))
            ->when($excludeAppointmentId, fn ($q) => $q->whereDoesntHave(
                'appointmentService',
                fn ($s) => $s->where('appointment_id', $excludeAppointmentId),
            ))
            ->exists();

        return $occupied
            ? ['ok' => false, 'reason' => 'This facility is currently occupied by another service.']
            : ['ok' => true, 'reason' => null];
    }

    // Hard gate, unlike isStaffAvailable() above — a room is a single
    // physical resource that can't serve two appointments at once, so
    // unlike therapist scheduling (soft/advisory only, see class doc
    // comment), room booking IS time-blocked: this is called at
    // assignment time in AppointmentService and actually rejects a
    // conflicting request rather than just ranking candidates.
    //
    // $excludeAppointmentId excludes the room's OTHER assignments on this
    // SAME appointment — a single visit's several services (e.g. a facial
    // then a massage) all share one appointment-level date/time in this
    // schema (no per-service sub-slots), so they'd trivially "overlap"
    // themselves. Reusing the same room across one client's own services is
    // the normal case, not a conflict; only a genuinely different
    // appointment competing for the same room at an overlapping time is.
    public function isFacilityAvailable(int $facilityId, string $date, string $startTime, int $durationMinutes, ?int $excludeAssignmentId = null, ?int $excludeAppointmentId = null): array
    {
        $start = Carbon::parse("{$date} {$startTime}");
        $end = (clone $start)->addMinutes($durationMinutes);

        $assignments = TherapistAssignment::with('appointmentService.appointment.services.serviceVariant')
            ->where('facility_id', $facilityId)
            ->whereIn('assignment_status', ['Assigned', 'In Progress'])
            ->when($excludeAssignmentId, fn ($q) => $q->where('id', '!=', $excludeAssignmentId))
            ->get();

        foreach ($assignments as $assignment) {
            $otherAppointmentId = $assignment->appointmentService?->appointment?->id;
            if ($excludeAppointmentId && $otherAppointmentId === $excludeAppointmentId) {
                continue;
            }
            if ($this->overlapsAppointment($assignment, $date, $start, $end)) {
                return ['ok' => false, 'reason' => 'This facility is already booked during the requested time.'];
            }
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * Why a facility can't be used for a visit, or null. A room under maintenance (or off
     * the books) takes nobody new — but whoever is in service in it keeps it until they are
     * done, and the same visit's next service in that sitting may stay with them.
     */
    public function facilityOutOfUse(int $facilityId, ?int $appointmentId = null): ?string
    {
        $facility = \App\Models\Facility::find($facilityId, ['id', 'name', 'status', 'is_available']);
        if (! $facility || ($facility->is_available && $facility->status !== 'Maintenance')) {
            return null;
        }

        $herePresently = $appointmentId && TherapistAssignment::where('facility_id', $facilityId)
            ->where('assignment_status', 'In Progress')
            ->whereHas('appointmentService', fn ($q) => $q->where('appointment_id', $appointmentId))
            ->exists();
        if ($herePresently) {
            return null;
        }

        return $facility->is_available
            ? "{$facility->name} is under maintenance, so nobody new can use it. Pick another facility."
            : "{$facility->name} is not in use. Pick another facility.";
    }

    public function suggestTherapists(int $branchId, int $serviceId, string $date, string $startTime, int $durationMinutes): Collection
    {
        return Staff::where('spa_branch_id', $branchId)
            ->where('role', 'therapist')
            ->where('status', 'active')
            ->get()
            ->filter(function (Staff $staff) use ($serviceId, $date, $startTime, $durationMinutes) {
                return $this->isStaffQualified($staff->id, $serviceId)['ok']
                    && $this->staffMatchesSchedule($staff->id, $date, $startTime, $durationMinutes)['ok']
                    && $this->isStaffAvailable($staff->id, $date, $startTime, $durationMinutes)['ok'];
            })
            ->values();
    }

    private function overlapsAppointment(TherapistAssignment $assignment, string $date, Carbon $start, Carbon $end): bool
    {
        $appointment = $assignment->appointmentService?->appointment;

        if (! $appointment || $appointment->appointment_date->format('Y-m-d') !== $date) {
            return false;
        }

        if (in_array($appointment->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW, Appointment::STATUS_COMPLETED], true)) {
            return false;
        }

        $otherStart = Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->appointment_time);
        $otherEnd = (clone $otherStart)->addMinutes($this->estimatedDurationMinutes($appointment));

        return $start->lt($otherEnd) && $end->gt($otherStart);
    }

    // Sum of duration_minutes across this appointment's non-cancelled
    // services — the "estimated total duration" used as the scheduled
    // window's length, since there's no per-service scheduled sub-slot.
    // Falls back to 30 minutes per service if a variant relation somehow
    // failed to load, so a data gap never collapses the window to zero.
    // Public because AppointmentResource reuses this exact sum as the
    // client-facing "total duration" figure rather than re-deriving it.
    public function estimatedDurationMinutes(Appointment $appointment): int
    {
        return $appointment->services
            ->where('status', '!=', 'Cancelled')
            ->sum(fn ($service) => $service->lengthMinutes() ?? 30);
    }

    // Same duration math, but only services still outstanding (Pending or
    // In Progress) — "how much service time is actually left," unlike
    // estimatedDurationMinutes()'s whole-visit total (which deliberately
    // keeps counting Completed services for booking-conflict math and the
    // Rooms/Therapist tabs' "Total duration" label — that figure stays
    // as-is; this is a separate, narrower one for "Est. service time").
    public function remainingDurationMinutes(Appointment $appointment): int
    {
        return $appointment->services
            ->whereIn('status', ['Pending', 'In Progress'])
            ->sum(fn ($service) => $service->lengthMinutes() ?? 30);
    }

    /**
     * When the branch closes for a visit starting at this date and time, or null
     * when there is no closing to keep to (no hours set, open all day, closed).
     * Same conventions as branchIsOpen().
     */
    public function closingFor(int $branchId, string $date, string $startTime): ?Carbon
    {
        $schedule = BranchSchedule::where('spa_branch_id', $branchId)
            ->where('day_of_week', Carbon::parse($date)->format('l'))
            ->first();
        if (! $schedule || $schedule->is_closed || ! $schedule->opening_time || ! $schedule->closing_time) {
            return null;
        }

        $open = Carbon::parse("{$date} {$schedule->opening_time}");
        $close = Carbon::parse("{$date} {$schedule->closing_time}");
        if ($open->eq($close)) {
            return null;
        }
        $time = Carbon::parse("{$date} {$startTime}");

        return $close->gt($open) ? $close : ($time->gte($open) ? $close->copy()->addDay() : $close);
    }

    /**
     * Why a visit, as it stands now, would still be running when the branch
     * closes — or null when it finishes in time. What is left of it is counted
     * from now (or its booked time, if that is later): the longest service in
     * progress, then everything not started, one after another. Asked after
     * something was added to it (a service, a package, an add-on, a longer
     * length), so nothing added keeps a visit going past closing.
     */
    public function finishesPastClosing(Appointment $appointment): ?string
    {
        $past = $this->pastClosing($appointment);

        return $past
            ? "That would keep this visit going past closing at {$past['closes']->format('g:i A')} (it would end about {$past['ends']->format('g:i A')}). Choose a shorter service, or leave it off."
            : null;
    }

    /**
     * When the visit would end against when the branch closes, if that is
     * after closing — counted from now, or from the booked time when that
     * is later. Null when it fits (or the branch has no closing time that day).
     *
     * @return array{closes: Carbon, ends: Carbon}|null
     */
    public function pastClosing(Appointment $appointment): ?array
    {
        $date = $appointment->appointment_date->format('Y-m-d');
        $closes = $this->closingFor($appointment->spa_branch_id, $date, $appointment->appointment_time);
        if (! $closes) {
            return null;
        }

        $services = $appointment->services()->with('therapistAssignments')->whereIn('status', ['Pending', 'In Progress'])->get();
        $now = now();
        $running = $services->where('status', 'In Progress')->map(function ($service) use ($now) {
            $started = $service->therapistAssignments->pluck('started_at')->filter()->min();
            $length = $service->lengthMinutes() ?? 30;

            return $started ? max(0, (int) ceil($now->diffInMinutes(Carbon::parse($started)->addMinutes($length), false))) : $length;
        })->max() ?? 0;
        $waiting = $services->where('status', 'Pending')->sum(fn ($service) => $service->lengthMinutes() ?? 30);

        $from = Carbon::parse("{$date} {$appointment->appointment_time}");
        $ends = ($now->gt($from) ? $now->copy() : $from)->addMinutes($running + $waiting);
        return $ends->lte($closes) ? null : ['closes' => $closes, 'ends' => $ends];
    }
}
