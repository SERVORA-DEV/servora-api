<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\AppointmentServiceItem;
use App\Models\Facility;
use App\Models\Staff;
use App\Models\StaffQualification;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Support\BusinessDay;
use App\Support\RotationTurn;
use App\Support\ServiceKind;
use Carbon\Carbon;

// Everything the front desk's Assign Staff & Facility drawer needs for ONE booking, in one read:
// the booking's services still to be done, and for every therapist and every facility whether each
// service can go to them, and why not. One staff member or facility can then be picked once and
// put on every service it fits.
//
// Nothing new is decided here: each answer is the rule the server applies when the assignment is
// really made (AppointmentService::assignTherapistInternal / checkRoomEligibility) — who does the
// service, home-service staff, shift (waived once timed in), room set-up and maintenance — plus the
// clashes with other bookings (advisory for staff, a hard gate for rooms), asked once for everyone
// instead of per service per person. Turn order is TherapistQueueCalculator's.
//
// A visit's services share the booking's time (the schema has no per-service time slots), so each
// service is judged over its own length from that time, and the visit's own other services never
// clash with it: one sitting.
class AssignmentPlanner
{
    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private AppointmentAvailabilityService $availability,
        private TherapistQueueCalculator $rotation,
        private StaffAttendanceWarning $standing,
    ) {
    }

    public function options(User $user, string $appointmentUuid)
    {
        $branchIds = $this->spaBusinessRepository->branchIdsForUser($user);

        $appointment = Appointment::whereIn('spa_branch_id', $branchIds)
            ->where('uuid', $appointmentUuid)
            ->with('services.serviceVariant.service', 'services.therapistAssignments.staff', 'services.therapistAssignments.facility')
            ->firstOrFail();

        if ($stale = AppointmentService::staleRefusal($appointment)) {
            return $stale;
        }

        return response()->json(['data' => $this->plan($appointment)]);
    }

    /**
     * The time a booking's staff and facility are judged for: the booked date and time while it is only a
     * reservation, now once the client is here (they are served as soon as someone is free). Rooms are
     * time-blocked over this same window when one is assigned (AppointmentService::checkRoomEligibility), so what
     * the drawer offers is what the server accepts.
     *
     * @return array{mode: string, date: string, time: string}
     */
    public static function window(Appointment $appointment): array
    {
        if ($appointment->status === Appointment::STATUS_SCHEDULED) {
            return ['mode' => 'reserve', 'date' => $appointment->appointment_date->format('Y-m-d'), 'time' => (string) $appointment->appointment_time];
        }

        return ['mode' => 'now', 'date' => now()->format('Y-m-d'), 'time' => now()->format('H:i:s')];
    }

    /**
     * The booking's services still to do, and every therapist and facility against each. Reads the
     * booking fresh, so it can be asked right after services were added to it.
     *
     * Two modes, from where the booking stands:
     *  - 'reserve' (booked, not here yet): everything is judged at the booked date and time, so staff and a facility
     *    can be held for it and the client is served on arrival without waiting. Who is busy now doesn't matter unless
     *    they will still be busy then.
     *  - 'now' (checked in, waiting): judged from this moment, with who is free right now, who is with a client and until
     *    about when. Reservations still ahead count, so a held slot is never given away.
     *
     * @return array{mode: string, at: string, services: array<int, array<string, mixed>>, staff: array<int, array<string, mixed>>, facilities: array<int, array<string, mixed>>}
     */
    public function plan(Appointment $appointment): array
    {
        $appointment->load('services.serviceVariant.service', 'services.therapistAssignments.staff', 'services.therapistAssignments.facility', 'branch.business.settings');

        ['mode' => $mode, 'date' => $date, 'time' => $time] = self::window($appointment);
        $isHome = $appointment->isHomeService();

        // Add-ons ride with their main service; started and finished services are not asked about.
        $services = $appointment->services
            ->filter(fn (AppointmentServiceItem $s) => ! $s->isAddon() && $s->status === 'Pending')
            ->values();

        $staff = $this->staff($appointment, $services, $date, $time, $isHome, $mode);
        $warned = collect($staff)->keyBy('uuid');

        return [
            // What the answer is for: a reservation judged at the booked time, or who is free now.
            'mode' => $mode,
            'at' => substr($time, 0, 5),
            // Each service also says whether the staff member already on it is on leave, absent, late or on a
            // day off (the Assign drawer then asks for a replacement).
            'services' => $services->map(function (AppointmentServiceItem $s) use ($isHome, $warned) {
                $row = $this->serviceRow($s, $isHome);
                $row['staff_warning'] = $row['staff'] ? ($warned[$row['staff']['uuid']]['warning'] ?? null) : null;

                return $row;
            })->all(),
            'staff' => $staff,
            'facilities' => $this->facilities($appointment, $services, $date, $time),
        ];
    }

    /**
     * When what each person or room is doing right now should be over (a service under way: its start plus its length),
     * keyed by id, as H:i. One query.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function freeAt(string $column, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $until = [];
        TherapistAssignment::whereIn($column, $ids)
            ->where('assignment_status', 'In Progress')
            ->whereNotNull('started_at')
            ->with('appointmentService.serviceVariant')
            ->get()
            ->each(function (TherapistAssignment $a) use (&$until, $column) {
                $end = Carbon::parse($a->started_at)->addMinutes($a->appointmentService?->lengthMinutes() ?? 30);
                $id = $a->{$column};
                if (! isset($until[$id]) || $end->gt($until[$id])) {
                    $until[$id] = $end;
                }
            });

        return array_map(fn (Carbon $c) => $c->format('H:i'), $until);
    }

    /** @return array<string, mixed> */
    private function serviceRow(AppointmentServiceItem $service, bool $isHome): array
    {
        $current = $service->therapistAssignments
            ->first(fn (TherapistAssignment $a) => $a->assignment_status === 'Assigned' && ($a->staff_id !== null || $a->facility_id !== null));
        $staffed = $service->therapistAssignments->first(fn (TherapistAssignment $a) => $a->assignment_status === 'Assigned' && $a->staff_id !== null);
        $roomed = $service->therapistAssignments->first(fn (TherapistAssignment $a) => $a->assignment_status === 'Assigned' && $a->facility_id !== null);

        return [
            'uuid' => $service->uuid,
            'name' => $service->displayName(),
            'minutes' => $service->lengthMinutes() ?? 30,
            'needs_staff' => ServiceKind::needsTherapist($service->kind()),
            'needs_facility' => ! $isHome && $service->needsFacility(),
            'staff' => $staffed?->staff ? ['uuid' => $staffed->staff->uuid, 'name' => $staffed->staff->first_name . ' ' . $staffed->staff->last_name] : null,
            'facility' => $roomed?->facility ? ['uuid' => $roomed->facility->uuid, 'name' => $roomed->facility->name] : null,
            'assigned' => $current !== null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function staff(Appointment $appointment, $services, string $date, string $time, bool $isHome, string $mode): array
    {
        $therapists = Staff::where('spa_branch_id', $appointment->spa_branch_id)
            ->where('role', 'therapist')
            ->where('status', 'active')
            ->with('schedules')
            ->orderBy('first_name')
            ->get();
        if ($therapists->isEmpty() || $services->isEmpty()) {
            return [];
        }
        $ids = $therapists->pluck('id')->all();

        $rows = $this->rotation->forBranch($appointment->spa_branch_id, $date, $therapists)->keyBy('staff_id');

        // "Qualified for everything until anything is configured, then a whitelist" — as isQualified().
        $qualified = StaffQualification::whereIn('staff_id', $therapists->pluck('id'))->get()->groupBy('staff_id')
            ->map(fn ($g) => $g->pluck('service_id')->all());

        // How many bookings each already has that day (to spread reservations fairly), one query.
        $workload = TherapistAssignment::whereIn('staff_id', $ids)
            ->whereIn('assignment_status', ['Assigned', 'In Progress'])
            ->whereHas('appointmentService.appointment', fn ($q) => $q
                ->whereDate('appointment_date', $date)
                ->where('id', '!=', $appointment->id)
                ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW, Appointment::STATUS_COMPLETED]))
            ->selectRaw('staff_id, count(*) as n')->groupBy('staff_id')->pluck('n', 'staff_id');

        // Today's attendance and shifts: who is on leave, absent or late.
        $attendance = Attendance::whereIn('staff_id', $ids)
            ->where('attendance_date', BusinessDay::attendanceDate($appointment->spa_branch_id, $date))
            ->get()->keyBy('staff_id');
        $branchSchedule = BranchSchedule::where('spa_branch_id', $appointment->spa_branch_id)
            ->where('day_of_week', Carbon::parse($date)->format('l'))->first();
        $grace = AttendanceStatusCalculator::graceMinutesFor($appointment->branch?->business);
        $standing = $therapists->mapWithKeys(fn (Staff $t) => [$t->id => $this->standing->check($t, $attendance->get($t->id), $date, $t->schedules, $branchSchedule, $grace)]);

        // Clashes per service, over that service's own length (one query for everyone each).
        // For "free now" someone mid-service is not a clash (their busy-until is shown); only reservations ahead are.
        $conflicts = [];
        foreach ($services as $service) {
            $conflicts[$service->uuid] = $this->availability->staffConflicts($therapists->pluck('id')->all(), $date, $time, $service->lengthMinutes() ?? 30, $appointment->id, includeRunning: $mode === 'reserve');
        }
        $busyUntil = $this->freeAt('staff_id', $ids);

        return $therapists->map(function (Staff $staff) use ($services, $rows, $qualified, $conflicts, $date, $time, $isHome, $workload, $standing, $busyUntil) {
            $warning = $standing[$staff->id] ?? null;
            $row = $rows->get($staff->id) ?? [];
            $onFloor = (bool) ($row['in_queue'] ?? false);
            // Timing in is what waives the shift check (assignTherapistInternal), even if they timed out since.
            $timedIn = ($row['checked_in_at'] ?? null) !== null;
            $inService = ($row['rotation_status'] ?? 'free') === RotationTurn::IN_SERVICE;

            $can = [];
            foreach ($services as $service) {
                $serviceId = $service->serviceVariant?->service_id;
                $reason = null;
                if (! ServiceKind::needsTherapist($service->kind())) {
                    $reason = 'This service needs no staff';
                } elseif ($serviceId && isset($qualified[$staff->id]) && $qualified[$staff->id] && ! in_array($serviceId, $qualified[$staff->id], true)) {
                    $reason = "Doesn't do this service";
                } elseif ($isHome && HomeServiceRules::staffRefusal($staff)) {
                    $reason = 'Not available for home service';
                } elseif ($warning && in_array($warning['kind'], ['on_leave', 'absent'], true)) {
                    $reason = $warning['kind'] === 'on_leave' ? 'On leave today' : 'Absent today';
                } elseif (isset($conflicts[$service->uuid][$staff->id])) {
                    $reason = $conflicts[$service->uuid][$staff->id];
                } elseif (! $timedIn) {
                    $shift = $this->availability->staffMatchesSchedule($staff->id, $date, $time, $service->lengthMinutes() ?? 30);
                    if (! $shift['ok']) {
                        $reason = str_contains((string) $shift['reason'], 'day off') ? 'Day off' : 'Not on shift then';
                    }
                }
                $can[$service->uuid] = ['ok' => $reason === null, 'reason' => $reason];
            }

            return [
                'uuid' => $staff->uuid,
                'name' => trim("{$staff->first_name} {$staff->last_name}"),
                'specialties' => $staff->specialtyList(),
                // available (free or has a client waiting) | busy (with a client now) | not_timed_in
                'state' => ! $onFloor ? 'not_timed_in' : ($inService ? 'busy' : 'available'),
                'rotation_status' => $row['rotation_status'] ?? 'free',
                // Place in today's turn order (null when not on the floor); who is next for THESE services
                // is worked out by the drawer among the people who can do them.
                'queue_position' => $row['position'] ?? null,
                'gender' => $staff->gender,
                // When what they are doing right now should be over (H:i), or null when they are not with a client.
                'busy_until' => $inService ? ($busyUntil[$staff->id] ?? null) : null,
                // Bookings already on their day, for sharing reservations out.
                'workload' => (int) ($workload[$staff->id] ?? 0),
                // On leave, absent, late or on a day off (StaffAttendanceWarning); late and day off
                // can still be assigned, the desk is warned.
                'warning' => $warning ? ['kind' => $warning['kind'], 'message' => $warning['message']] : null,
                'can' => $can,
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function facilities(Appointment $appointment, $services, string $date, string $time): array
    {
        if ($services->isEmpty()) {
            return [];
        }

        $facilities = Facility::with('services:id,uuid')->where('spa_branch_id', $appointment->spa_branch_id)->orderBy('name')->get();

        // A room the visit's other services already use is fine whatever it is set up for.
        $own = TherapistAssignment::whereIn('assignment_status', ['Assigned', 'In Progress'])
            ->whereNotNull('facility_id')
            ->whereHas('appointmentService', fn ($q) => $q->where('appointment_id', $appointment->id))
            ->pluck('facility_id')->all();

        $can = [];
        foreach ($services as $service) {
            $can[$service->uuid] = $this->availability->facilityReasons(
                $facilities, $service->serviceVariant?->service_id, $date, $time, $service->lengthMinutes() ?? 30, $appointment->id, $own,
            );
        }

        $occupiedUntil = $this->freeAt('facility_id', $facilities->pluck('id')->all());

        return $facilities->map(function (Facility $f) use ($services, $can, $occupiedUntil) {
            $per = [];
            foreach ($services as $service) {
                $reason = $can[$service->uuid][$f->id] ?? null;
                $per[$service->uuid] = ['ok' => $reason === null, 'reason' => $reason];
            }

            // occupied_until: when the client in it now should be done (H:i), or null when it is empty.
            return ['uuid' => $f->uuid, 'name' => $f->name, 'category' => $f->category, 'occupied_until' => $occupiedUntil[$f->id] ?? null, 'can' => $per];
        })->values()->all();
    }
}
