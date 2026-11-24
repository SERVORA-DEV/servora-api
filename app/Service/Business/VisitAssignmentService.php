<?php

namespace App\Service\Business;

use App\Enums\AppointmentServiceStatus;
use App\Enums\AssignmentStatus;
use App\Enums\StaffStatus;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\Facility;
use App\Models\Staff;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Repository\Business\AppointmentRepository;
use App\Repository\Business\TherapistAssignmentRepository;
use App\Repository\SpaBusinessRepository;
use App\Support\Visits\AutoAssignment;
use App\Support\Branches\BusinessDay;
use App\Support\Visits\RotationTurn;
use App\Support\Catalog\ServiceKind;
use App\Support\Catalog\ServiceParts;
use App\Support\Visits\StaleVisit;
use App\Support\Visits\ServicePause;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Who does each service and where: the therapists and rooms that can take it, assigning them
 * one at a time, all at once (the Assign drawer) or automatically, parts of a service done by
 * several staff, and taking an assignment or a room off again.
 *
 * Split out of AppointmentService (which still hands every call on, for its older callers).
 */
class VisitAssignmentService
{
    public function __construct(
        private AppointmentRepository $appointmentRepository,
        private TherapistAssignmentRepository $therapistAssignmentRepository,
        private SpaBusinessRepository $spaBusinessRepository,
        private AppointmentAvailabilityService $availabilityService,
        private AttendanceStatusCalculator $attendanceStatusCalculator,
        private TherapistQueueCalculator $therapistQueueCalculator,
    ) {}

    // ── Therapist / room assignment ─────────────────────────────────────

    // Every active therapist at this service's branch — the picker still
    // shows the whole roster rather than hiding anyone, but each row now
    // carries whether they're qualified for THIS service so the front desk
    // sees the reason up front instead of discovering it as a 422 from
    // assignTherapist(). Combined with today's attendance check-in and
    // their schedule for this exact appointment slot. Reuses
    // AttendanceStatusCalculator::resolve() (same status/shift-window logic
    // the Attendance pages already use) and
    // AppointmentAvailabilityService::staffMatchesSchedule()/isStaffQualified()
    // (the exact checks assignTherapistInternal() already gates on) rather
    // than reimplementing any of them.
    //
    // `pickable` is check-in AND qualification — deliberately the same pair
    // assignTherapistInternal() enforces. A therapist working outside their
    // scheduled shift is still pickable, just flagged
    // 'checked_in_off_schedule', since attendance (not schedule) is the
    // real-world fact that matters for "can they actually do this right
    // now"; qualification, by contrast, is a hard skill requirement that is
    // never bypassed.
    //
    // `qualified` is returned alongside `state` rather than folded into it:
    // the six states describe presence/schedule, and qualification is
    // orthogonal to all of them (an off-schedule therapist can be qualified,
    // a checked-in one can be unqualified).
    public function therapistOptions(User $user, string $appointmentServiceUuid)
    {
        $branchIds = $this->branchIds($user);

        $service = AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
            ->where('uuid', $appointmentServiceUuid)
            ->with('serviceVariant', 'appointment', 'therapistAssignments')
            ->firstOrFail();

        $appointment = $service->appointment;
        $date = $appointment->appointment_date->format('Y-m-d');
        $time = $appointment->appointment_time;
        $duration = $service->lengthMinutes() ?? 30;
        $dayOfWeek = Carbon::parse($date)->format('l');

        // Qualification is tracked at the Service level, not per variant —
        // qualified for "Swedish Massage" covers every duration of it — so
        // this hops variant -> service, exactly as assignTherapistInternal()
        // does.
        $serviceId = $service->serviceVariant?->service_id;

        $therapists = Staff::where('spa_branch_id', $appointment->spa_branch_id)
            ->where('role', 'therapist')
            ->where('status', StaffStatus::Active->value)
            ->with('schedules')
            ->orderBy('first_name')
            ->get();

        $attendanceByStaffId = Attendance::whereIn('staff_id', $therapists->pluck('id'))
            ->where('attendance_date', BusinessDay::attendanceDate($appointment->spa_branch_id, $date))
            ->get()
            ->keyBy('staff_id');

        $branchSchedule = BranchSchedule::where('spa_branch_id', $appointment->spa_branch_id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        // The branch's turn order for this date, keyed by staff id. Shares one
        // calculator with the front-desk queue board so the two screens can
        // never disagree about whose turn it is.
        $rotationByStaffId = $this->therapistQueueCalculator
            ->forBranch($appointment->spa_branch_id, $date, $therapists)
            ->keyBy('staff_id');

        // Anyone already holding this service is out of the running for it —
        // the picker filters them out of the list, so naming one of them as
        // "next up" would point at a row that isn't on screen.
        $heldStaffIds = $service->therapistAssignments
            ->whereIn('assignment_status', [AssignmentStatus::Assigned->value, AssignmentStatus::InProgress->value])
            ->pluck('staff_id')
            ->filter()
            ->all();
        $alreadyOnThisServiceUuids = $therapists
            ->whereIn('id', $heldStaffIds)
            ->pluck('uuid')
            ->all();

        $appointment->loadMissing('branch.business.settings');
        $grace = AttendanceStatusCalculator::graceMinutesFor($appointment->branch?->business);

        // A home visit can only go to staff marked "Available for home service".
        $isHome = $appointment->isHomeService();

        // Who is already booked over this time, and with whom (one query for everyone).
        // A home visit keeps them for the trip there and back too.
        [$from, $span] = HomeServiceRules::widen($appointment->isHomeService(), (int) $appointment->spa_branch_id, $date, $time, $duration);
        $conflicts = $this->availabilityService->staffConflicts($therapists->pluck('id')->all(), $date, $from, $span, $appointment->id);

        $options = $therapists->map(function (Staff $staff) use ($attendanceByStaffId, $date, $time, $duration, $branchSchedule, $serviceId, $rotationByStaffId, $grace, $isHome, $conflicts) {
            $attendance = $attendanceByStaffId->get($staff->id);
            $resolved = $this->attendanceStatusCalculator->resolve($staff, $attendance, $date, $staff->schedules, $branchSchedule, graceMinutes: $grace);
            $scheduleCheck = $this->availabilityService->staffMatchesSchedule($staff->id, $date, $time, $duration);

            // No resolvable service (a variant that's since been deleted)
            // leaves everyone qualified rather than blocking the whole
            // picker — assignTherapistInternal() would surface the real
            // problem on submit.
            $qualified = $serviceId === null
                || $this->availabilityService->isStaffQualified($staff->id, $serviceId)['ok'];

            $homeOk = ! $isHome || $staff->takesHomeService();
            $checkedIn = (bool) $attendance?->check_in_at;
            $matchesWindow = $scheduleCheck['ok'];

            $state = match (true) {
                $checkedIn && $resolved['is_day_off'] => 'checked_in_day_off',
                $checkedIn && $matchesWindow => 'available',
                $checkedIn && ! $matchesWindow => 'checked_in_off_schedule',
                ! $checkedIn && $resolved['is_day_off'] => 'day_off',
                ! $checkedIn && $matchesWindow => 'not_checked_in',
                default => 'not_scheduled',
            };

            $rotation = $rotationByStaffId->get($staff->id);

            return [
                'uuid' => $staff->uuid,
                'name' => trim("{$staff->first_name} {$staff->last_name}"),
                // What they do, main one first (App\Support\Staff\StaffSpecialty).
                'specialties' => $staff->specialtyList(),
                'state' => $state,
                'qualified' => $qualified,
                // False when this is a home visit and they aren't marked
                // "Available for home service" (always true for a visit in the spa).
                'home_service_ok' => $homeOk,
                // Turn order — see TherapistQueueCalculator. Null position
                // means they aren't on the floor (never timed in, or already
                // timed out), which is a different thing from being last.
                'queue_position' => $rotation['position'] ?? null,
                'in_queue' => (bool) ($rotation['in_queue'] ?? false),
                'rotation_status' => $rotation['rotation_status'] ?? 'free',
                'last_completed_at' => $rotation['last_completed_at']?->format('H:i'),
                // Set below, once the whole list exists — it's a property of
                // the list, not of any single therapist.
                'is_next_up' => false,
                // Checked in plus qualified — a real, in-person fact always
                // overrides a static schedule (day off included), but never
                // a missing skill; see assignTherapistInternal(), which
                // skips the schedule check entirely once checked in while
                // always running the qualification check, matching this
                // exactly.
                // Never someone off that day in Employees (leave, sick, absent), timed in or not.
                'pickable' => $checkedIn && $qualified && $homeOk && empty($rotation['time_off']),
                'time_off' => $rotation['time_off'] ?? null,
                'checked_in_at' => $attendance?->check_in_at?->format('H:i'),
                'is_day_off' => $resolved['is_day_off'],
                'scheduled_start' => $resolved['scheduled_start'],
                'scheduled_end' => $resolved['scheduled_end'],
                // Already booked over this booking's time, and with whom; null when nothing
                // clashes. Advisory like the rest of staff booking (see the availability
                // service's class note): assigning is still allowed, the desk is told.
                'conflict' => $conflicts[$staff->id] ?? null,
            ];
        })
            // Turn order, with everyone who can't take this booking pushed
            // below: on the floor first, then off it, and within each group
            // the unqualified last. Nobody is dropped — the front desk should
            // be able to see why someone isn't an option.
            ->sortBy([
                fn (array $a, array $b) => ($b['home_service_ok'] <=> $a['home_service_ok']),
                fn (array $a, array $b) => ($b['qualified'] <=> $a['qualified']),
                fn (array $a, array $b) => ($b['in_queue'] <=> $a['in_queue']),
                fn (array $a, array $b) => ($a['queue_position'] ?? PHP_INT_MAX) <=> ($b['queue_position'] ?? PHP_INT_MAX),
            ])
            ->values();

        // "Next up" for THIS booking, which is narrower than the branch-wide
        // rotation: the first therapist who is on the floor, not in the
        // middle of a service (one assigned to a booking that hasn't started
        // still counts — App\Support\Visits\RotationTurn), qualified for this
        // service, and not already assigned to it. A #1 who can't perform
        // this service isn't actually next here, and saying otherwise sends
        // the front desk down a dead end.
        $nextUpIndex = $options->search(fn (array $row) => RotationTurn::canTakeNext($row['in_queue'], $row['rotation_status'])
            && $row['qualified']
            && $row['home_service_ok']
            && ! in_array($row['uuid'], $alreadyOnThisServiceUuids, true));

        if ($nextUpIndex !== false) {
            $options = $options->replace([$nextUpIndex => array_merge($options[$nextUpIndex], ['is_next_up' => true])]);
        }

        return response()->json(['data' => $options->values()]);
    }

    // Every facility of the branch for one booked service, each with why it can't take it (null
    // when it can): out of rotation or under maintenance, not set up for this service, or already
    // booked over this booking's time. The same rules assignRoom() enforces
    // (checkRoomEligibility), so what the picker offers is what the server accepts.
    public function facilityOptions(User $user, string $appointmentServiceUuid)
    {
        $branchIds = $this->branchIds($user);

        $service = AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
            ->where('uuid', $appointmentServiceUuid)
            ->with('serviceVariant', 'appointment')
            ->firstOrFail();

        $appointment = $service->appointment;
        $date = $appointment->appointment_date->format('Y-m-d');
        $serviceId = $service->serviceVariant?->service_id;

        $facilities = Facility::with('services:id,uuid')
            ->where('spa_branch_id', $appointment->spa_branch_id)
            ->orderBy('name')
            ->get();

        // A room the client's own other services already use is fine whatever it is set up for.
        $theirRooms = TherapistAssignment::whereIn('assignment_status', [AssignmentStatus::Assigned->value, AssignmentStatus::InProgress->value])
            ->whereNotNull('facility_id')
            ->whereHas('appointmentService', fn ($q) => $q->where('appointment_id', $appointment->id)->where('id', '!=', $service->id))
            ->pluck('facility_id')
            ->all();

        $reasons = $this->availabilityService->facilityReasons(
            $facilities,
            $serviceId,
            $date,
            $appointment->appointment_time,
            $service->lengthMinutes() ?? 30,
            $appointment->id,
            $theirRooms,
        );

        $options = $facilities->map(function (Facility $facility) use ($reasons) {
            $reason = $reasons[$facility->id];

            return [
                'uuid' => $facility->uuid,
                'name' => $facility->name,
                'category' => $facility->category,
                'available' => $reason === null,
                'reason' => $reason,
            ];
        });

        return response()->json(['data' => $options->values()]);
    }

    public function assignTherapist(User $user, string $appointmentServiceUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentServiceUuid, $payload) {
            $service = AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $appointmentServiceUuid)
                ->lockForUpdate()
                ->with('serviceVariant', 'appointment')
                ->firstOrFail();

            if ($stale = StaleVisit::refusal($service->appointment)) {
                return $stale;
            }

            if (in_array($service->status, [AppointmentServiceStatus::Cancelled->value, AppointmentServiceStatus::Completed->value], true)) {
                return response()->json(['message' => 'Cannot assign staff to a cancelled or completed service.'], 422);
            }
            if ($refusal = $this->addonFollows($service, 'shares its staff and facility')) {
                return $refusal;
            }

            // Room-only reservation — a room can be locked in for a service
            // before a therapist is picked (assigned ahead of time, or right
            // when the client is up for service); staff can be claimed onto
            // the same row later via assignTherapistInternal().
            if (empty($payload['staff_uuid'])) {
                if (empty($payload['facility_uuid'])) {
                    return response()->json(['message' => 'Choose a staff member or a facility.'], 422);
                }

                $roomResult = $this->assignRoomOnlyInternal($service, $service->appointment, $payload['facility_uuid']);
                if (! $roomResult['ok']) {
                    return response()->json(['message' => $roomResult['reason']], 422);
                }

                return new AppointmentResource($this->appointmentRepository->findByUuid($service->appointment->uuid));
            }

            $staff = $this->resolveStaffForBranch($payload['staff_uuid'], $service->appointment->spa_branch_id);
            if (! $staff) {
                return response()->json(['message' => 'Staff member not found or inactive at this branch.'], 422);
            }

            $part = $this->partFor($service, $payload['part_uuid'] ?? null);
            if ($part === false) {
                return response()->json(['message' => 'That part isn\'t part of this service.'], 422);
            }
            $result = $this->assignTherapistInternal($service, $service->appointment, $staff, $part);
            if (! $result['ok']) {
                return response()->json(['message' => $result['reason']], 422);
            }

            if (! empty($payload['facility_uuid'])) {
                $roomResult = $this->assignRoomInternal($result['assignment'], $service->appointment, $service, $payload['facility_uuid']);
                if (! $roomResult['ok']) {
                    return response()->json(['message' => $roomResult['reason']], 422);
                }
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($service->appointment->uuid));
        });
    }

    // ── Automatic staff and facility ─────────────────────────────────────

    // Gives a booking the staff and facility it still lacks: for a reservation with "any staff" as it is
    // made (createAppointment / createClientAppointment), and from the Assign drawer's Auto-assign button.
    // AssignmentPlanner supplies the facts (who is qualified, on shift, not on leave, not already booked;
    // which rooms are set up and free), AutoAssignment decides (one person for the whole visit when someone
    // can do it, else the best fit for each service; one room when one fits), and the assignment itself goes
    // through the same internals as a hand assignment, so every rule still holds. It never fails a booking:
    // what could not be placed stays for the desk, and comes back as warnings.
    //
    // $mode 'missing' fills only what is empty; 'replace_unavailable' also replaces staff who are on leave,
    // absent or on a day off (the desk's one-tap replacement); 'facility_only' leaves staff alone (the client
    // named a therapist, or automatic staff is off) and still gives each service its room.
    //
    // @return array<int, string> warnings for the desk
    public function autoAssign(Appointment $appointment, string $mode = 'missing'): array
    {
        // A home visit is held at the client's home: with staff left alone there is nothing to place.
        if ($mode === 'facility_only' && $appointment->isHomeService()) {
            return [];
        }

        $plan = app(AssignmentPlanner::class)->plan($appointment);
        if (! $plan['services']) {
            return [];
        }

        $replace = $mode === 'replace_unavailable';
        $facilityOnly = $mode === 'facility_only';
        $staffByUuid = collect($plan['staff'])->keyBy('uuid');
        $unavailableKinds = ['on_leave', 'absent', 'day_off'];

        $services = array_map(function (array $s) use ($replace, $facilityOnly, $staffByUuid, $unavailableKinds) {
            $current = $s['staff']['uuid'] ?? null;
            $gone = $replace && $current && in_array($staffByUuid[$current]['warning']['kind'] ?? null, $unavailableKinds, true);

            return [
                'uuid' => $s['uuid'], 'name' => $s['name'],
                'needs_staff' => $s['needs_staff'] && ! $facilityOnly, 'needs_facility' => $s['needs_facility'],
                'takes_facility' => $s['takes_facility'] ?? true,
                'has_staff' => $current !== null && ! $gone, 'has_facility' => $s['facility'] !== null,
            ];
        }, $plan['services']);

        $staff = array_map(fn (array $p) => [
            'uuid' => $p['uuid'], 'name' => $p['name'],
            'workload' => $p['workload'] ?? 0, 'position' => $p['queue_position'] ?? null,
            'warning' => ($p['warning']['kind'] ?? null) === 'late',
            // A client who is here is given someone free now before someone mid-service.
            'busy' => ($plan['mode'] ?? 'reserve') === 'now' && ($p['state'] ?? '') === 'busy',
            'can' => array_map(fn ($c) => (bool) $c['ok'], $p['can']),
        ], $plan['staff']);

        $facilities = array_map(fn (array $f) => [
            'uuid' => $f['uuid'], 'name' => $f['name'],
            'clients_in' => $f['clients_in'] ?? 0,
            'can' => array_map(fn ($c) => (bool) $c['ok'], $f['can']),
        ], $plan['facilities']);

        $decision = AutoAssignment::decide($services, $staff, $facilities);
        $warnings = $decision['warnings'];

        $items = $appointment->services->keyBy('uuid');
        foreach ($services as $row) {
            $item = $items->get($row['uuid']);
            $staffUuid = $decision['staff'][$row['uuid']] ?? null;
            $roomUuid = $decision['facility'][$row['uuid']] ?? null;
            if (! $item || (! $staffUuid && ! $roomUuid)) {
                continue;
            }

            $assignment = null;
            if ($staffUuid) {
                $person = $this->resolveStaffForBranch($staffUuid, $appointment->spa_branch_id);
                $result = $person ? $this->assignTherapistInternal($item, $appointment, $person) : ['ok' => false, 'reason' => 'Staff member not found.', 'assignment' => null];
                if ($result['ok']) {
                    $assignment = $result['assignment'];
                } else {
                    $warnings[] = ($row['name'] ?: 'A service').': '.$result['reason'];
                }
            }

            if ($roomUuid) {
                $assignment ??= $item->therapistAssignments()->where('assignment_status', AssignmentStatus::Assigned->value)->whereNotNull('staff_id')->first();
                $placed = $assignment
                    ? $this->assignRoomInternal($assignment, $appointment, $item, $roomUuid)
                    : $this->assignRoomOnlyInternal($item, $appointment, $roomUuid);
                if (! $placed['ok']) {
                    $warnings[] = ($row['name'] ?: 'A service').': '.$placed['reason'];
                }
            }
        }

        if (! $facilityOnly) {
            $warnings = array_merge($warnings, $this->fillParts($appointment, $staff, $services));
        }

        return array_values(array_unique($warnings));
    }

    // Puts the people picked for a service's parts on them ([{part_uuid, staff_uuid}]).
    //
    // @return array<int, string> what could not be done, naming the service and part
    public function chooseParts(Appointment $appointment, int $branchId, AppointmentServiceItem $line, array $picks): array
    {
        $problems = [];
        $name = $line->displayName() ?: 'A service';
        foreach ($picks as $pick) {
            $part = $this->partFor($line, $pick['part_uuid'] ?? null);
            $person = $this->resolveStaffForBranch($pick['staff_uuid'] ?? '', $branchId);
            if (! $part || ! $person) {
                $problems[] = "{$name}: ".(! $part ? 'that part isn\'t part of this service.' : 'that staff member wasn\'t found at this branch.');
                continue;
            }
            $result = $this->assignTherapistInternal($line, $appointment, $person, $part);
            if (! $result['ok']) {
                $problems[] = "{$name} ({$part->name}): {$result['reason']}";
            }
        }

        return $problems;
    }

    // Every service done by several staff where at least one part has someone (picked by the
    // desk or the client, or the requested therapist) gets the rest filled now.
    //
    // @return array<int, string> warnings for the desk
    public function fillPickedParts(Appointment $appointment): array
    {
        if (! \App\Models\Service::hasParts()) {
            return [];
        }
        $plan = app(AssignmentPlanner::class)->plan($appointment);
        $rows = array_values(array_filter($plan['services'] ?? [], fn (array $s) => ! empty($s['parts'])
            && collect($s['parts'])->contains(fn ($p) => $p['staff'] !== null)
            && collect($s['parts'])->contains(fn ($p) => $p['staff'] === null)));

        return $rows ? $this->fillParts($appointment, $this->rankable($plan), $rows) : [];
    }

    // AssignmentPlanner's staff, in the shape AutoAssignment ranks.
    private function rankable(array $plan): array
    {
        return array_map(fn (array $p) => [
            'uuid' => $p['uuid'], 'name' => $p['name'],
            'workload' => $p['workload'] ?? 0, 'position' => $p['queue_position'] ?? null,
            'warning' => ($p['warning']['kind'] ?? null) === 'late',
            'busy' => ($plan['mode'] ?? 'reserve') === 'now' && ($p['state'] ?? '') === 'busy',
            'can' => array_map(fn ($c) => (bool) $c['ok'], $p['can']),
        ], $plan['staff'] ?? []);
    }

    // A service done by several staff at once gets someone for every part still empty: the best
    // ranked who can do it and isn't already on it (AutoAssignment::bestFor). A part the desk or
    // the client already chose stays as it is, so choosing one is enough.
    //
    // @return array<int, string> warnings for the desk
    private function fillParts(Appointment $appointment, array $staff, array $services): array
    {
        $warnings = [];
        $items = $appointment->services()->with('serviceVariant')->get()->keyBy('uuid');
        foreach ($services as $row) {
            $item = $items->get($row['uuid']);
            if (! $item || ! $row['needs_staff'] || $item->status !== AppointmentServiceStatus::Pending->value) {
                continue;
            }
            $missing = ServiceParts::missing($item);
            foreach ($missing as $part) {
                $on = ServiceParts::staffedRows($item)->pluck('staff_id')->all();
                $taken = Staff::whereIn('id', $on)->pluck('uuid')->all();
                $uuid = AutoAssignment::bestFor($row['uuid'], $staff, $taken);
                $person = $uuid ? $this->resolveStaffForBranch($uuid, $appointment->spa_branch_id) : null;
                $result = $person
                    ? $this->assignTherapistInternal($item, $appointment, $person, $part)
                    : ['ok' => false, 'reason' => 'nobody else free can do it.'];
                if (! $result['ok']) {
                    $warnings[] = ($row['name'] ?: 'A service').' ('.$part->name.'): '.$result['reason'];
                }
            }
        }

        return $warnings;
    }

    // The Assign drawer's Auto-assign: the same, for a booking that already exists.
    public function autoAssignAppointment(User $user, string $appointmentUuid, string $mode = 'missing')
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentUuid, $mode) {
            $appointment = Appointment::whereIn('spa_branch_id', $branchIds)->where('uuid', $appointmentUuid)->lockForUpdate()->firstOrFail();

            if ($stale = StaleVisit::refusal($appointment)) {
                return $stale;
            }

            $warnings = $this->autoAssign($appointment, $mode);
            $resource = new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));

            return $warnings ? $resource->additional(['warnings' => $warnings]) : $resource;
        });
    }

    // Several services of one booking given their staff and facility together (the Assign Staff &
    // Facility drawer's Confirm). Each line goes through the same rules as assignTherapist() and
    // assignRoom(); unlike separate calls, it is all or nothing: if any service can't take what was
    // chosen, nothing at all is saved and the message names that service.
    //
    // $items: [{service_uuid, staff_uuid?, facility_uuid?}]. A facility alone goes on the row that
    // already carries the service's staff, else it is held for the service until someone is picked.
    public function assignBatch(User $user, string $appointmentUuid, array $items)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentUuid, $items) {
            $appointment = Appointment::whereIn('spa_branch_id', $branchIds)
                ->where('uuid', $appointmentUuid)
                ->lockForUpdate()
                ->firstOrFail();

            if ($stale = StaleVisit::refusal($appointment)) {
                return $stale;
            }

            // Thrown, not returned: leaving the transaction is what undoes the lines already saved.
            $fail = fn (string $message) => throw new HttpResponseException(response()->json(['message' => $message], 422));

            foreach ($items as $item) {
                $service = AppointmentServiceItem::where('appointment_id', $appointment->id)
                    ->where('uuid', $item['service_uuid'])
                    ->lockForUpdate()
                    ->with('serviceVariant', 'appointment')
                    ->first();
                if (! $service) {
                    $fail("That service isn't on this booking.");
                }
                $name = $service->displayName() ?: 'This service';

                if (in_array($service->status, [AppointmentServiceStatus::Cancelled->value, AppointmentServiceStatus::Completed->value, AppointmentServiceStatus::InProgress->value], true)) {
                    $fail("{$name} has already started or finished, so its staff and facility can't be changed here.");
                }
                if ($service->isAddon()) {
                    $fail('An add-on shares its staff and facility with its main service.');
                }

                $staffUuid = $item['staff_uuid'] ?? null;
                $facilityUuid = $item['facility_uuid'] ?? null;

                if ($staffUuid) {
                    $staff = $this->resolveStaffForBranch($staffUuid, $appointment->spa_branch_id);
                    if (! $staff) {
                        $fail("{$name}: that staff member wasn't found or isn't active at this branch.");
                    }
                    $part = $this->partFor($service, $item['part_uuid'] ?? null);
                    if ($part === false) {
                        $fail("{$name}: that part isn't part of this service.");
                    }
                    $result = $this->assignTherapistInternal($service, $appointment, $staff, $part);
                    if (! $result['ok']) {
                        $fail("{$name}: {$result['reason']}");
                    }
                    if ($facilityUuid) {
                        $room = $this->assignRoomInternal($result['assignment'], $appointment, $service, $facilityUuid);
                        if (! $room['ok']) {
                            $fail("{$name}: {$room['reason']}");
                        }
                    }
                } elseif ($facilityUuid) {
                    $carrier = $service->therapistAssignments()
                        ->where('assignment_status', AssignmentStatus::Assigned->value)
                        ->whereNotNull('staff_id')
                        ->first();
                    $room = $carrier
                        ? $this->assignRoomInternal($carrier, $appointment, $service, $facilityUuid)
                        : $this->assignRoomOnlyInternal($service, $appointment, $facilityUuid);
                    if (! $room['ok']) {
                        $fail("{$name}: {$room['reason']}");
                    }
                }
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // The part a request names, for a service done by several staff: null when none was named,
    // false when it isn't one of this service's parts.
    private function partFor(AppointmentServiceItem $service, ?string $partUuid): \App\Models\ServicePart|false|null
    {
        if (! $partUuid) {
            return null;
        }

        return ServiceParts::forLine($service)->firstWhere('uuid', $partUuid) ?? false;
    }

    public function cancelAssignment(User $user, string $assignmentUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $assignmentUuid) {
            $assignment = TherapistAssignment::whereHas('appointmentService.appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $assignmentUuid)
                ->with('appointmentService.appointment')
                ->firstOrFail();

            if ($assignment->assignment_status === AssignmentStatus::InProgress->value) {
                return response()->json(['message' => 'Cannot remove a staff member currently performing the service.'], 422);
            }

            // Closes the dangling-row case: an Assigned-but-never-started
            // row left over on a service that was already completed via a
            // different assignment (assignment_status alone wouldn't catch
            // this — it's still just 'Assigned').
            if (in_array($assignment->appointmentService->status, [AssignmentStatus::Completed->value, AssignmentStatus::Cancelled->value], true)) {
                return response()->json(['message' => 'This service is already completed — its assignments can no longer be changed.'], 422);
            }

            if ($stale = StaleVisit::refusal($assignment->appointmentService->appointment)) {
                return $stale;
            }
            if ($refusal = $this->addonFollows($assignment->appointmentService, 'shares its staff and facility')) {
                return $refusal;
            }

            // Only the person comes off: the service keeps its facility on this
            // row, and whoever is picked next claims it (assignTherapistInternal).
            // A row with nothing else on it is cancelled.
            if ($assignment->staff_id && $assignment->facility_id) {
                $assignment->update(['staff_id' => null]);
            } else {
                $this->therapistAssignmentRepository->cancel($assignment);
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($assignment->appointmentService->appointment->uuid));
        });
    }

    // Targets a specific therapist_assignment row (a room is attached per
    // assignment, not per appointment or even per service — rule: don't
    // assume one room per appointment).
    public function assignRoom(User $user, string $assignmentUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $assignmentUuid, $payload) {
            $assignment = TherapistAssignment::whereHas('appointmentService.appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $assignmentUuid)
                ->lockForUpdate()
                ->with('appointmentService.serviceVariant', 'appointmentService.appointment')
                ->firstOrFail();

            if (in_array($assignment->assignment_status, [AssignmentStatus::Completed->value, AssignmentStatus::Cancelled->value], true)) {
                return response()->json(['message' => 'Cannot assign a facility to a completed or cancelled assignment.'], 422);
            }

            $service = $assignment->appointmentService;
            $appointment = $service->appointment;

            if ($stale = StaleVisit::refusal($appointment)) {
                return $stale;
            }

            // Same dangling-row case as cancelAssignment() — this
            // assignment row is only 'Assigned', but its service already
            // finished via a different assignment.
            if (in_array($service->status, [AppointmentServiceStatus::Completed->value, AppointmentServiceStatus::Cancelled->value], true)) {
                return response()->json(['message' => 'This service is already completed — its assignments can no longer be changed.'], 422);
            }
            if ($refusal = $this->addonFollows($service, 'shares its staff and facility')) {
                return $refusal;
            }

            $result = $this->assignRoomInternal($assignment, $appointment, $service, $payload['facility_uuid']);
            if (! $result['ok']) {
                return response()->json(['message' => $result['reason']], 422);
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // Takes the room off an assignment but keeps its therapist — the "×" on
    // the room chip. (cancelAssignment is the other way round: it takes the
    // therapist and keeps the room.) A room-only row has nothing left once its
    // room goes, so it is cancelled instead.
    public function clearRoom(User $user, string $assignmentUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $assignmentUuid) {
            $assignment = TherapistAssignment::whereHas('appointmentService.appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $assignmentUuid)
                ->lockForUpdate()
                ->with('appointmentService.appointment')
                ->firstOrFail();

            $service = $assignment->appointmentService;

            if (! $assignment->facility_id) {
                return response()->json(['message' => 'This service has no facility to remove.'], 422);
            }
            if (in_array($assignment->assignment_status, [AssignmentStatus::Completed->value, AssignmentStatus::Cancelled->value], true)
                || in_array($service->status, [AppointmentServiceStatus::Completed->value, AppointmentServiceStatus::Cancelled->value], true)) {
                return response()->json(['message' => 'This service is already finished — its facility can no longer be changed.'], 422);
            }
            // The client is in the room: it can be changed (assignRoom), not
            // taken away, until the service is completed.
            if ($assignment->assignment_status === AssignmentStatus::InProgress->value || $service->status === AssignmentStatus::InProgress->value) {
                return response()->json(['message' => 'This service is under way in that facility — it can be moved to another facility, but not left without one until it is completed.'], 422);
            }
            // The same checks as assigning one.
            if ($stale = StaleVisit::refusal($service->appointment)) {
                return $stale;
            }
            if ($refusal = $this->addonFollows($service, 'shares its staff and facility')) {
                return $refusal;
            }

            $assignment->update($assignment->staff_id
                ? ['facility_id' => null]
                : ['facility_id' => null, 'assignment_status' => AssignmentStatus::Cancelled->value]);
            // A service done by several staff at once leaves the room together.
            $this->roomForEveryPart($service, $assignment->id, null);

            return new AppointmentResource($this->appointmentRepository->findByUuid($assignment->appointmentService->appointment->uuid));
        });
    }

    // A failed requested-therapist assignment never fails the booking — it's
    // collected as a warning instead (see createAppointment()'s doc comment).
    public function applyRequestedTherapist(Appointment $appointment, int $branchId, AppointmentServiceItem $service, string $staffUuid, array &$warnings): void
    {
        $staff = $this->resolveStaffForBranch($staffUuid, $branchId);

        if (! $staff) {
            $warnings[] = 'Requested staff member not found or inactive at this branch.';

            return;
        }

        $service->load('serviceVariant.service');
        // The therapist the booking asked for is for the services someone
        // performs — a facility-based one is simply left without.
        if (! ServiceKind::needsTherapist($this->kindOf($service))) {
            return;
        }
        $result = $this->assignTherapistInternal($service, $appointment, $staff);
        if (! $result['ok']) {
            $warnings[] = $result['reason'];
        }
    }

    // The booking asked for this therapist by name: kept on the appointment
    // (Appointment::requestedStaff), whether or not they could be assigned.
    public function rememberRequestedTherapist(Appointment $appointment, int $branchId, ?string $staffUuid): void
    {
        if (! $staffUuid || ! Appointment::remembersRequests()) {
            return;
        }
        $staff = $this->resolveStaffForBranch($staffUuid, $branchId);
        if ($staff) {
            $appointment->forceFill(['requested_staff_id' => $staff->id])->save();
        }
    }

    public function resolveStaffForBranch(string $staffUuid, int $branchId): ?Staff
    {
        return Staff::where('uuid', $staffUuid)
            ->where('spa_branch_id', $branchId)
            ->where('status', StaffStatus::Active->value)
            ->first();
    }

    // ── Change of therapist while paused (App\Support\Visits\ServicePause) ──

    // The client wants someone else, or the therapist can't go on: while the service is paused
    // the front desk gives it to another therapist who is available now (timed in, qualified,
    // not off, not busy). The one who stopped is marked Handed Over — they keep when they
    // worked, and are free at once; the new one carries on from the same point (the clock
    // continues where it was) and is paid for the service on completing it. Optionally in
    // another room. The services of the same sitting (same staff, same room) go with it.
    //
    // $payload: staff_uuid (the new one), reason, facility_uuid? (another room),
    // assignment_uuid? (who is replaced, when several do the service at once).
    public function changeTherapist(User $user, string $appointmentServiceUuid, array $payload, ?\Illuminate\Http\Request $request = null)
    {
        if (! ServicePause::ready()) {
            return response()->json(['message' => 'Changing the therapist of a service in progress is not available yet.'], 422);
        }
        if (! $this->spaBusinessRepository->findForUser($user)) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }
        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentServiceUuid, $payload, $user, $request) {
            $service = AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $appointmentServiceUuid)
                ->lockForUpdate()
                ->with('serviceVariant', 'appointment', 'therapistAssignments')
                ->firstOrFail();
            $appointment = $service->appointment;

            if ($refusal = $this->addonFollows($service, 'changes therapist')) {
                return $refusal;
            }
            if ($stale = StaleVisit::refusal($appointment)) {
                return $stale;
            }
            if ($service->status !== AppointmentServiceStatus::InProgress->value || ! ServicePause::isPaused($service)) {
                return response()->json(['message' => 'Pause the service first, then change the therapist.'], 422);
            }
            if ($appointment->isHomeService()) {
                return response()->json(['message' => 'A home visit keeps its therapist: they are at the client\'s home.'], 422);
            }
            if (! ServiceKind::needsTherapist($this->kindOf($service))) {
                return response()->json(['message' => 'This service needs no staff.'], 422);
            }

            // Who is replaced: the one doing it, or the one named when several do it at once.
            $working = $service->therapistAssignments
                ->where('assignment_status', AssignmentStatus::InProgress->value)
                ->whereNotNull('staff_id')
                ->values();
            $old = ! empty($payload['assignment_uuid'])
                ? $working->firstWhere('uuid', $payload['assignment_uuid'])
                : ($working->count() === 1 ? $working->first() : null);
            if (! $old) {
                return response()->json(['message' => $working->isEmpty()
                    ? 'Nobody is doing this service to be replaced.'
                    : 'Choose which therapist to replace.'], 422);
            }

            $staff = $this->resolveStaffForBranch((string) $payload['staff_uuid'], $appointment->spa_branch_id);
            if (! $staff) {
                return response()->json(['message' => 'Staff member not found or inactive at this branch.'], 422);
            }
            if ($staff->id === $old->staff_id) {
                return response()->json(['message' => 'That therapist is already doing this service.'], 422);
            }

            // The services of this sitting (same staff, same room), all paused together.
            $lines = app(ServiceExecutionService::class)->sittingMates($service, AppointmentServiceStatus::InProgress->value)
                ->prepend($service)
                ->filter(fn (AppointmentServiceItem $line) => ServicePause::isPaused($line))
                ->values();
            $lines->each(fn (AppointmentServiceItem $line) => $line->loadMissing('serviceVariant', 'therapistAssignments'));

            // A package's services run one after another in one sitting, all in progress until the
            // package is completed. Those whose time had run out when it was paused are done: they
            // are completed for the one who did them (and paid to them). Only the rest is handed
            // over, its clock going on from where the finished ones ended.
            [$finished, $lines, $carryOnFrom] = $this->finishedBeforePause($lines, (int) $old->staff_id);

            if ($problem = $this->takeOverProblem($staff, $appointment, $lines)) {
                return response()->json(['message' => $problem], 422);
            }

            $facility = null;
            if (! empty($payload['facility_uuid'])) {
                $facility = Facility::where('uuid', $payload['facility_uuid'])->where('spa_branch_id', $appointment->spa_branch_id)->first();
                if (! $facility) {
                    return response()->json(['message' => 'Facility not found at this branch.'], 422);
                }
                foreach ($lines as $line) {
                    $mine = $line->therapistAssignments->where('assignment_status', AssignmentStatus::InProgress->value)->firstWhere('staff_id', $old->staff_id);
                    if ((int) $facility->id !== (int) $mine?->facility_id) {
                        $check = $this->checkRoomEligibility($facility, $appointment, $line, $mine?->id);
                        if (! $check['ok']) {
                            return response()->json(['message' => $check['reason']], 422);
                        }
                    }
                }
            }

            $reason = trim((string) ($payload['reason'] ?? ''));
            $newName = trim("{$staff->first_name} {$staff->last_name}");
            foreach ($finished as $done) {
                app(ServiceExecutionService::class)->completeFinishedLine($done['line'], $done['from'], $done['to']);
                app(\App\Repository\AuditLogRepository::class)->record(
                    $user->id, 'appointment_services', $done['line']->id, 'Update',
                    ['service' => $done['line']->displayName(), 'status' => AppointmentServiceStatus::InProgress->value],
                    ['service' => $done['line']->displayName(), 'status' => AppointmentServiceStatus::Completed->value, 'therapist' => $old->staffName(), 'reason' => 'Done before the package was handed over'],
                    $request,
                );
            }
            foreach ($lines as $line) {
                $ids = collect([$line->id])->merge($line->addons()->pluck('id'))->all();
                $rows = TherapistAssignment::whereIn('appointment_service_id', $ids)
                    ->where('assignment_status', AssignmentStatus::InProgress->value)
                    ->where('staff_id', $old->staff_id)
                    ->get();
                foreach ($rows as $row) {
                    $this->handOver($row, $staff, $facility, Carbon::parse($line->paused_at), $reason, $newName, $carryOnFrom);
                }
                app(\App\Repository\AuditLogRepository::class)->record(
                    $user->id, 'appointment_services', $line->id, 'Replace',
                    ['therapist' => $old->staffName()],
                    array_filter(['service' => $line->displayName(), 'therapist' => $newName, 'facility' => $facility?->name, 'reason' => $reason]),
                    $request,
                );
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // Why this therapist can't take over these paused services now, or null. Available now: an
    // active therapist, timed in today, not off, qualified for each service, not in another
    // client's service, and not already on (or handed over from) any of these services.
    private function takeOverProblem(Staff $staff, Appointment $appointment, $lines): ?string
    {
        $name = trim("{$staff->first_name} {$staff->last_name}");
        if ($staff->role !== 'therapist') {
            return 'This employee doesn\'t perform services.';
        }
        $today = BusinessDay::forBranch((int) $appointment->spa_branch_id);
        if ($away = $this->availabilityService->leaveOn($staff->id, $today)) {
            return "{$name} is off today ({$away}). Pick someone else.";
        }
        $timedIn = Attendance::where('staff_id', $staff->id)
            ->where('attendance_date', BusinessDay::attendanceDate($appointment->spa_branch_id, $today))
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->exists();
        if (! $timedIn) {
            return "{$name} isn't timed in right now. Pick someone who is here.";
        }
        foreach ($lines as $line) {
            $serviceId = $line->serviceVariant?->service_id;
            if ($serviceId && ! ($q = $this->availabilityService->isStaffQualified($staff->id, $serviceId))['ok']) {
                return $q['reason'];
            }
            $theirs = $line->therapistAssignments->firstWhere('staff_id', $staff->id);
            if ($theirs && $theirs->assignment_status !== AssignmentStatus::Cancelled->value) {
                return $theirs->assignment_status === AssignmentStatus::HandedOver->value
                    ? "{$name} already handed this service over. Pick someone else."
                    : "{$name} is already on this service.";
            }
        }
        $busy = $this->availabilityService->isStaffCurrentlyBusy($staff->id, null, $lines->pluck('id')->all());
        if (! $busy['ok']) {
            return $busy['reason'];
        }
        // Held for another client's reservation that hasn't arrived yet: not while what is left of
        // this service would run into it — the same rule as starting a service.
        $appointment->loadMissing('branch');
        $grace = $appointment->branch
            ? (int) (\App\Service\Client\BookingPolicy::for($appointment->branch)['reservation_grace_minutes'] ?? \App\Support\Visits\ReservationHold::DEFAULT_GRACE_MINUTES)
            : \App\Support\Visits\ReservationHold::DEFAULT_GRACE_MINUTES;
        $left = (int) $lines->max(function (AppointmentServiceItem $line) {
            $started = $line->therapistAssignments->where('assignment_status', AssignmentStatus::InProgress->value)->pluck('started_at')->filter()->min();
            $length = $line->lengthMinutes() ?? 30;

            return $started ? max(1, $length - (int) Carbon::parse($started)->diffInMinutes(Carbon::parse($line->paused_at), true)) : $length;
        });
        if ($held = $this->availabilityService->reservedElsewhere($staff->id, $appointment->id, now(), max(1, $left), $grace)) {
            return $held;
        }

        return null;
    }

    // One row handed over: the old therapist stops at the pause, the new one carries on in
    // their place (same part of the service, same start, so the clock goes on where it was).
    // Of a paused sitting's services (a package: one after another, by their order), the ones
    // whose time had run out by the pause — each with when it ran — and the ones left, with when
    // the first of them began. The last one is never counted done: the client is still there for
    // it, even past its time. One service alone, or no start to go by: nothing is split.
    //
    // @return array{0: Collection<int, array{line: AppointmentServiceItem, from: Carbon, to: Carbon}>, 1: Collection<int, AppointmentServiceItem>, 2: ?Carbon}
    private function finishedBeforePause($lines, int $staffId): array
    {
        $none = [collect(), $lines, null];
        if ($lines->count() < 2) {
            return $none;
        }
        $ordered = $lines->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
        $starts = $ordered->flatMap(fn (AppointmentServiceItem $line) => $line->therapistAssignments
            ->where('assignment_status', AssignmentStatus::InProgress->value)
            ->where('staff_id', $staffId)
            ->pluck('started_at'))
            ->filter();
        $pausedAt = $ordered->first()->paused_at;
        if ($starts->isEmpty() || ! $pausedAt) {
            return $none;
        }
        $start = Carbon::parse($starts->min());
        $ranMinutes = (Carbon::parse($pausedAt)->getTimestamp() - $start->getTimestamp()) / 60;

        $finished = collect();
        $minutes = 0;
        foreach ($ordered->slice(0, -1) as $line) {
            $length = $line->lengthMinutes();
            if ($length === null) {
                break;
            }
            // Its add-ons are part of its time.
            $length += (int) $line->liveAddons()->sum(fn (AppointmentServiceItem $addon) => $addon->lengthMinutes() ?? 0);
            if ($minutes + $length > $ranMinutes) {
                break;
            }
            $finished->push(['line' => $line, 'from' => $start->copy()->addMinutes($minutes), 'to' => $start->copy()->addMinutes($minutes + $length)]);
            $minutes += $length;
        }
        if ($finished->isEmpty()) {
            return $none;
        }
        $doneIds = $finished->map(fn (array $done) => $done['line']->id)->all();

        return [$finished, $ordered->reject(fn (AppointmentServiceItem $line) => in_array($line->id, $doneIds, true))->values(), $start->copy()->addMinutes($minutes)];
    }

    // $startedAt: when this part of a package really began, after the services of it that were
    // already done (finishedBeforePause); null keeps the start as it is.
    private function handOver(TherapistAssignment $row, Staff $staff, ?Facility $facility, Carbon $stoppedAt, string $reason, string $newName, ?Carbon $startedAt = null): void
    {
        $values = [
            'staff_id' => $staff->id,
            'facility_id' => $facility?->id ?? $row->facility_id,
            'assignment_status' => AssignmentStatus::InProgress->value,
            'assigned_at' => now(),
            'started_at' => $startedAt ?? $row->started_at,
            'completed_at' => null,
            'remarks' => null,
            'service_part_id' => $row->service_part_id,
            'part_name' => $row->part_name,
            'commission_share' => $row->commission_share,
            'commission_amount' => null,
            'commission_rate' => null,
            'commission_type' => null,
        ];

        $row->update([
            'assignment_status' => AssignmentStatus::HandedOver->value,
            'started_at' => $startedAt ?? $row->started_at,
            'completed_at' => $stoppedAt,
            'remarks' => trim(($row->remarks ? $row->remarks.' · ' : '')."Handed over to {$newName}".($reason !== '' ? ": {$reason}" : '').'.'),
        ]);

        // One row per person per service: a cancelled one of theirs is used again.
        $earlier = TherapistAssignment::where('appointment_service_id', $row->appointment_service_id)->where('staff_id', $staff->id)->first();
        $earlier
            ? $earlier->update($values)
            : TherapistAssignment::create($values + ['appointment_service_id' => $row->appointment_service_id]);
    }

    // Qualification -> schedule, in that order, so the first failing check
    // gives the most useful reason. Never throws — the caller decides
    // whether a failure is fatal (explicit assignTherapist action) or just
    // a warning (requested therapist at creation time). Deliberately no
    // double-booking check here — reservations aren't time-blocked (walk-in
    // queue model, no real per-service time slots); the only real conflict
    // check is the real-time isStaffCurrentlyBusy() at startService().
    //
    // The schedule check is skipped entirely once the therapist has
    // actually checked in for attendance on this date — a real, in-person
    // fact overrides a static weekly shift definition (day off included):
    // if they're physically here and clocked in, front desk can assign them
    // regardless of what their configured schedule says. Qualification is
    // never bypassed this way — that's a hard skill requirement, not a
    // presence one.
    // $part: for a service done by several staff at once (Service::parts), which part this person
    // takes; left out, they take the first part nobody has yet.
    private function assignTherapistInternal(AppointmentServiceItem $service, Appointment $appointment, Staff $staff, ?\App\Models\ServicePart $part = null): array
    {
        if ($staff->role !== 'therapist') {
            return ['ok' => false, 'reason' => 'This employee doesn\'t perform services.', 'assignment' => null];
        }
        if (! ServiceKind::needsTherapist($this->kindOf($service))) {
            return ['ok' => false, 'reason' => 'This service needs no staff.', 'assignment' => null];
        }
        // A home visit goes to staff the owner has marked for home service —
        // never bypassed, like qualification.
        if ($appointment->isHomeService() && ($refusal = HomeServiceRules::staffRefusal($staff))) {
            return ['ok' => false, 'reason' => $refusal, 'assignment' => null];
        }

        $duration = $service->lengthMinutes() ?? 30;
        $date = $appointment->appointment_date->format('Y-m-d');

        // Off that day in Employees (leave, sick, absent): never given a client, even when timed in.
        $off = \App\Models\StaffTimeOff::overlapping($staff->id, $date, $date)->first();
        if ($off) {
            $label = \App\Models\StaffTimeOff::LABELS[$off->type] ?? 'Time off';
            return ['ok' => false, 'reason' => trim("{$staff->first_name} {$staff->last_name}") . " is off that day ({$label}, set in Employees). Pick someone else.", 'assignment' => null];
        }

        $checks = [
            $this->availabilityService->isStaffQualified($staff->id, $service->serviceVariant->service_id),
        ];

        $isCheckedIn = Attendance::where('staff_id', $staff->id)
            ->where('attendance_date', BusinessDay::attendanceDate($appointment->spa_branch_id, $date))
            ->whereNotNull('check_in_at')
            ->exists();

        if (! $isCheckedIn) {
            $checks[] = $this->availabilityService->staffMatchesSchedule($staff->id, $date, $appointment->appointment_time, $duration);
        }

        foreach ($checks as $check) {
            if (! $check['ok']) {
                return $check + ['assignment' => null];
            }
        }

        // Done by several staff at once: one person per part, each on their own row.
        $parts = ServiceParts::forLine($service);
        if ($parts->isNotEmpty()) {
            return $this->assignPart($service, $staff, $parts, $part);
        }

        // One staff member per service: someone already on it who hasn't
        // started is replaced (their row, and the room on it, are kept);
        // once it is under way the staff can't be changed here.
        $current = $service->therapistAssignments()
            ->whereIn('assignment_status', [AssignmentStatus::Assigned->value, AssignmentStatus::InProgress->value])
            ->whereNotNull('staff_id')
            ->first();
        if ($current) {
            if ($current->assignment_status === AssignmentStatus::InProgress->value) {
                return ['ok' => false, 'reason' => 'This service has already started with its staff member.', 'assignment' => null];
            }
            if ($current->staff_id !== $staff->id) {
                $current->update(['staff_id' => $staff->id, 'assigned_at' => now()]);
            }

            return ['ok' => true, 'reason' => null, 'assignment' => $current->fresh()];
        }

        // If a room was already reserved for this service ahead of a
        // therapist, claim that same row rather than creating a second one
        // — preserves the room instead of losing/duplicating it.
        $unclaimed = $this->therapistAssignmentRepository->findUnclaimedForService($service->id);
        $assignment = $unclaimed
            ? $this->therapistAssignmentRepository->claim($unclaimed, $staff->id)
            : $this->therapistAssignmentRepository->assign($service->id, $staff->id);

        return ['ok' => true, 'reason' => null, 'assignment' => $assignment];
    }

    // One part of a service done by several staff at once. Whoever is on that part and hasn't
    // started is replaced; one person can't do two parts of the same service. The row keeps the
    // part, its name and its share of the commission, and takes the room the other parts are in.
    private function assignPart(AppointmentServiceItem $service, Staff $staff, \Illuminate\Support\Collection $parts, ?\App\Models\ServicePart $part): array
    {
        $rows = ServiceParts::staffedRows($service);
        if ($part && ! $parts->contains('id', $part->id)) {
            return ['ok' => false, 'reason' => 'That part isn\'t part of this service.', 'assignment' => null];
        }

        $already = $rows->firstWhere('staff_id', $staff->id);
        if (! $part) {
            // Already on it: nothing to do. Else the first part nobody has yet.
            if ($already) {
                return ['ok' => true, 'reason' => null, 'assignment' => $already];
            }
            $part = ServiceParts::missing($service, $parts)->first();
            if (! $part) {
                return ['ok' => false, 'reason' => 'Every part of this service already has someone. Choose which part to change.', 'assignment' => null];
            }
        }
        if ($already && $already->service_part_id !== $part->id) {
            $theirs = $already->part_name ?: 'another part';

            return ['ok' => false, 'reason' => "This staff member is already doing {$theirs} of this service. One person can't do two parts.", 'assignment' => null];
        }

        $partValues = ['service_part_id' => $part->id, 'part_name' => $part->name, 'commission_share' => $part->share_percent];
        $current = $rows->firstWhere('service_part_id', $part->id);
        if ($current) {
            if ($current->assignment_status === AssignmentStatus::InProgress->value) {
                return ['ok' => false, 'reason' => "{$part->name} has already started with its staff member.", 'assignment' => null];
            }
            if ($current->staff_id !== $staff->id) {
                $current->update(['staff_id' => $staff->id, 'assigned_at' => now()] + $partValues);
            }

            return ['ok' => true, 'reason' => null, 'assignment' => $current->fresh()];
        }

        // A room held for the service before anyone was picked is claimed by the first part.
        $unclaimed = $this->therapistAssignmentRepository->findUnclaimedForService($service->id);
        $assignment = $unclaimed
            ? $this->therapistAssignmentRepository->claim($unclaimed, $staff->id)
            : $this->therapistAssignmentRepository->assign($service->id, $staff->id);
        $room = $assignment->facility_id ?? $rows->whereNotNull('facility_id')->first()?->facility_id;
        $assignment->update($partValues + ($room ? ['facility_id' => $room] : []));

        return ['ok' => true, 'reason' => null, 'assignment' => $assignment->fresh()];
    }

    // Unlike assignTherapistInternal() above, rooms ARE time-blocked: a
    // physical room can't serve two appointments at once, so both the
    // service-support check and isFacilityAvailable() are hard gates here,
    // not advisory (see AppointmentAvailabilityService's class doc comment).
    private function assignRoomInternal(TherapistAssignment $assignment, Appointment $appointment, AppointmentServiceItem $service, string $facilityUuid): array
    {
        $facility = Facility::where('uuid', $facilityUuid)->where('spa_branch_id', $appointment->spa_branch_id)->first();
        if (! $facility) {
            return ['ok' => false, 'reason' => 'Facility not found at this branch.'];
        }

        $roomCheck = $this->checkRoomEligibility($facility, $appointment, $service, $assignment->id);
        if (! $roomCheck['ok']) {
            return $roomCheck;
        }

        $before = $assignment->facility_id;
        $moving = $before && (int) $before !== (int) $facility->id && $service->status === AppointmentServiceStatus::InProgress->value;

        // A paused package (one sitting: same staff, same room) moves as one. Every service of it
        // must fit the new room before anything moves.
        $mates = collect();
        if ($moving && ServicePause::isPaused($service)) {
            $mates = app(ServiceExecutionService::class)->sittingMates($service, AppointmentServiceStatus::InProgress->value)
                ->filter(fn (AppointmentServiceItem $mate) => ServicePause::isPaused($mate))
                ->values();
            foreach ($mates as $mate) {
                $row = $mate->therapistAssignments->where('assignment_status', AssignmentStatus::InProgress->value)->firstWhere('facility_id', $before);
                $check = $this->checkRoomEligibility($facility, $appointment, $mate, $row?->id);
                if (! $check['ok']) {
                    return $check;
                }
            }
        }

        $this->therapistAssignmentRepository->assignFacility($assignment, $facility->id);
        $this->roomForEveryPart($service, $assignment->id, $facility);
        // A service under way (paused, or moved while running): its add-ons are in the room with it,
        // so they move too — else the old room would stay taken by this client until it is completed.
        // So do the rest of a paused package, with their add-ons, and the other staff on a service
        // done by several at once (their rows are still in the old room; this one has moved).
        if ($moving) {
            $ids = collect([$service])->merge($mates)
                ->flatMap(fn (AppointmentServiceItem $line) => $line->addons()->pluck('id')->push($line->id))
                ->all();
            TherapistAssignment::whereIn('appointment_service_id', $ids)
                ->whereIn('assignment_status', [AssignmentStatus::Assigned->value, AssignmentStatus::InProgress->value])
                ->where('facility_id', $before)
                ->get()
                ->each(fn (TherapistAssignment $row) => $row->update(['facility_id' => $facility->id]));
        }

        return ['ok' => true, 'reason' => null];
    }

    // A service done by several staff at once happens in one room: the others' rows follow.
    private function roomForEveryPart(AppointmentServiceItem $service, int $exceptId, ?Facility $facility): void
    {
        if (! \App\Models\Service::hasParts()) {
            return;
        }
        TherapistAssignment::where('appointment_service_id', $service->id)
            ->where('id', '!=', $exceptId)
            ->whereNotNull('service_part_id')
            ->where('assignment_status', AssignmentStatus::Assigned->value)
            ->get()
            ->each(fn (TherapistAssignment $row) => $row->update(['facility_id' => $facility?->id]));
    }

    // Reserves a room for a service with no therapist assigned yet (rule:
    // room independent of staff). Same hard gates as assignRoomInternal().
    private function assignRoomOnlyInternal(AppointmentServiceItem $service, Appointment $appointment, string $facilityUuid): array
    {
        $facility = Facility::where('uuid', $facilityUuid)->where('spa_branch_id', $appointment->spa_branch_id)->first();
        if (! $facility) {
            return ['ok' => false, 'reason' => 'Facility not found at this branch.'];
        }

        $roomCheck = $this->checkRoomEligibility($facility, $appointment, $service, null);
        if (! $roomCheck['ok']) {
            return $roomCheck;
        }

        $this->therapistAssignmentRepository->assignRoomOnly($service->id, $facility->id);

        return ['ok' => true, 'reason' => null];
    }

    // Shared by assignRoomInternal()/assignRoomOnlyInternal(): the room must
    // support the service being booked, and must be free for the
    // appointment's estimated time window (excluding this same assignment
    // row, if one already exists, so re-confirming a room doesn't conflict
    // with itself). A room with NO services configured at all is treated as
    // unrestricted (open to any service) rather than rejected outright —
    // this keeps every pre-existing room (created before this feature, or
    // simply not yet categorized) working exactly as before instead of
    // silently locking out every assignment until an owner manually
    // back-fills every room's service list.
    private function checkRoomEligibility(Facility $facility, Appointment $appointment, AppointmentServiceItem $service, ?int $excludeAssignmentId): array
    {
        // The client's own room — one of their other services is in it — is
        // fine whatever it is set up for: they stay put, and the same staff can
        // do both in one sitting (sittingMates).
        $theirs = TherapistAssignment::where('facility_id', $facility->id)
            ->whereIn('assignment_status', [AssignmentStatus::Assigned->value, AssignmentStatus::InProgress->value])
            ->whereHas('appointmentService', fn ($q) => $q->where('appointment_id', $appointment->id)->where('id', '!=', $service->id))
            ->exists();
        $serviceId = $service->serviceVariant?->service_id;
        if (! $theirs && $serviceId && $facility->services()->exists() && ! $facility->services()->where('services.id', $serviceId)->exists()) {
            return ['ok' => false, 'reason' => 'This facility does not support the selected service.'];
        }

        // Under maintenance: nobody new (whoever is in it now finishes first).
        if ($outOfUse = $this->availabilityService->facilityOutOfUse($facility->id, $appointment->id)) {
            return ['ok' => false, 'reason' => $outOfUse];
        }

        $duration = $service->lengthMinutes() ?? 30;
        // Judged for the booked time while it is a reservation, for now once the client is here (the same window the
        // Assign drawer offered rooms for).
        $window = AssignmentPlanner::window($appointment);

        return $this->availabilityService->isFacilityAvailable($facility->id, $window['date'], $window['time'], $duration, $excludeAssignmentId, $appointment->id);
    }

    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchIdsForUser($user);
    }

    // An add-on is never handled as a service of its own: it has no therapist,
    // room, Start or Complete — its main service's are its own.
    private function addonFollows(AppointmentServiceItem $service, string $what)
    {
        return $service->isAddon()
            ? response()->json(['message' => "An add-on {$what} with its main service."], 422)
            : null;
    }

    // time | procedure | facility — a service saved before types existed is
    // time-based (App\Support\Catalog\ServiceKind).
    private function kindOf(AppointmentServiceItem $service): string
    {
        return $service->kind();
    }
}
