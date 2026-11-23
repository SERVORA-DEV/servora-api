<?php

namespace App\Service\Business;

use App\Enums\AppointmentServiceStatus;
use App\Enums\AppointmentStatus;
use App\Enums\AssignmentStatus;
use App\Enums\BillingStatus;
use App\Enums\QueueStatus;
use App\Enums\StaffStatus;
use App\Exceptions\ActionRefused;
use App\Http\Resources\AppointmentResource;
use App\Models\Addon;
use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Attendance;
use App\Models\SpaBusinessSetting;
use App\Models\Staff;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Repository\BillingRepository;
use App\Repository\Business\AppointmentRepository;
use App\Repository\Business\AppointmentServiceRepository;
use App\Repository\Business\QueueRepository;
use App\Repository\Business\TherapistAssignmentRepository;
use App\Repository\SpaBusinessRepository;
use App\Service\Client\BookingPolicy;
use App\Support\Branches\BusinessDay;
use App\Support\Visits\ReservationHold;
use App\Support\Catalog\ServiceKind;
use App\Support\Catalog\ServiceParts;
use App\Support\Visits\ServiceStartRules;
use App\Support\Catalog\SharedPackages;
use App\Support\Visits\StaleVisit;
use App\Repository\AuditLogRepository;
use App\Support\Visits\ServicePause;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Running the services on a visit: starting one (with whoever shares the room or the client),
 * completing it, add-ons starting and finishing with their main service, and closing a visit
 * left open from an earlier day.
 *
 * Split out of AppointmentService (which still hands every call on, for its older callers).
 */
class ServiceExecutionService
{
    public function __construct(
        private AppointmentRepository $appointmentRepository,
        private AppointmentServiceRepository $appointmentServiceRepository,
        private TherapistAssignmentRepository $therapistAssignmentRepository,
        private QueueRepository $queueRepository,
        private SpaBusinessRepository $spaBusinessRepository,
        private AppointmentAvailabilityService $availabilityService,
        private BillingRepository $billingRepository,
        private BillingService $billingService,
    ) {}

    // A visit still in service when its day has ended, with nobody having done
    // anything about it: what was started is finished at its estimated end (when
    // it began plus its length, and the length of its add-ons) and not later than
    // the end of the day, so the duration availed is worked out from that; what was
    // never started is taken off. The visit is then ready to be billed.
    public function closeStaleVisit(Appointment $appointment): void
    {
        DB::transaction(function () use ($appointment) {
            $mains = $appointment->services()
                ->where('status', AppointmentServiceStatus::InProgress->value)
                ->when(Addon::ready(), fn ($q) => $q->whereNull('parent_appointment_service_id'))
                ->with('therapistAssignments', 'serviceVariant')
                ->lockForUpdate()
                ->get();
            foreach ($mains as $service) {
                $this->autoFinishService($service, $appointment);
            }

            $serviceIds = $appointment->services()->pluck('id');
            $waiting = $appointment->services()->where('status', AppointmentServiceStatus::Pending->value)->exists();
            if ($waiting) {
                $appointment->services()->where('status', AppointmentServiceStatus::Pending->value)->update(['status' => AppointmentServiceStatus::Cancelled->value]);
                TherapistAssignment::whereIn('appointment_service_id', $serviceIds)
                    ->where('assignment_status', AssignmentStatus::Assigned->value)
                    ->update(['assignment_status' => AssignmentStatus::Cancelled->value]);
                // Never done, so never charged: the bulk update did not fire the
                // line's events, hence the explicit recalculation.
                $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);
            }

            if ($appointment->queue && ! in_array($appointment->queue->queue_status, [QueueStatus::Completed->value, QueueStatus::Cancelled->value], true)
                && ! $appointment->services()->whereIn('status', [AppointmentServiceStatus::Pending->value, AppointmentServiceStatus::InProgress->value])->exists()) {
                $this->queueRepository->updateStatus($appointment->queue, QueueStatus::Completed->value, ['completed_at' => now()]);
            }
            // Covered by another client's shared package: closed with nothing to pay.
            $this->closeCoveredVisit($appointment);
        });
    }

    private function autoFinishService(AppointmentServiceItem $service, Appointment $appointment): void
    {
        $running = $service->therapistAssignments->where('assignment_status', AssignmentStatus::InProgress->value);
        $startedAt = $running->pluck('started_at')->filter()->min();

        $minutes = (int) ($service->duration_minutes ?? $service->serviceVariant?->duration_minutes ?? 0);
        $minutes += (int) $service->liveAddons()->sum('duration_minutes');

        $dayEnd = Carbon::parse($appointment->appointment_date)->endOfDay();
        $at = $startedAt ? $startedAt->copy()->addMinutes($minutes) : $dayEnd;
        // Left paused when the day ended: it stopped when it was paused.
        if (ServicePause::isPaused($service)) {
            $pausedAt = Carbon::parse($service->paused_at);
            if ($pausedAt->lessThan($at)) {
                $at = $pausedAt;
            }
            $service->update(['paused_at' => null]);
        }
        if ($at->greaterThan($dayEnd)) {
            $at = $dayEnd;
        }
        if ($startedAt && $at->lessThan($startedAt)) {
            $at = $startedAt->copy();
        }

        $this->startAddons($service, $at);
        foreach ($running as $assignment) {
            $assignment->update([
                'assignment_status' => AssignmentStatus::Completed->value,
                'completed_at' => $at,
                'remarks' => trim(($assignment->remarks ? $assignment->remarks.' · ' : '').'Finished automatically at its estimated end: nobody completed it before the day ended.'),
            ]);
        }
        $service->update(['status' => AppointmentServiceStatus::Completed->value]);
        app(CommissionCalculator::class)->freeze($service);
        $this->completeAddons($service, $at);
    }

    // Starts a main service's add-ons with it — the ones still waiting.
    private function startAddons(AppointmentServiceItem $main, $now): void
    {
        foreach ($main->liveAddons()->where('status', AppointmentServiceStatus::Pending->value) as $addon) {
            $this->rideWith($addon, $main, $now);
        }
    }

    // An add-on has no therapist or room of its own: it takes its main
    // service's — whoever is performing the main right now, where they are —
    // and is in progress from this moment. On a service nobody performs (a
    // sauna) that is the room alone, so no commission is earned on it.
    public function rideWith(AppointmentServiceItem $addon, AppointmentServiceItem $main, $now): void
    {
        $running = TherapistAssignment::where('appointment_service_id', $main->id)
            ->where('assignment_status', AssignmentStatus::InProgress->value)
            ->get();

        foreach ($running as $assignment) {
            TherapistAssignment::updateOrCreate(
                ['appointment_service_id' => $addon->id, 'staff_id' => $assignment->staff_id],
                [
                    'facility_id' => $assignment->facility_id,
                    'assignment_status' => AssignmentStatus::InProgress->value,
                    'assigned_at' => $now,
                    'started_at' => $now,
                    'completed_at' => null,
                ]
            );
        }

        $addon->update(['status' => AppointmentServiceStatus::InProgress->value]);
    }

    // Completes a main service's add-ons with it, and fixes what each earned.
    private function completeAddons(AppointmentServiceItem $main, $now): void
    {
        foreach ($main->liveAddons()->where('status', AppointmentServiceStatus::InProgress->value) as $addon) {
            TherapistAssignment::where('appointment_service_id', $addon->id)
                ->where('assignment_status', AssignmentStatus::InProgress->value)
                ->get()
                ->each(fn (TherapistAssignment $a) => $a->update(['assignment_status' => AssignmentStatus::Completed->value, 'completed_at' => $now]));
            $addon->update(['status' => AppointmentServiceStatus::Completed->value]);
            app(CommissionCalculator::class)->freeze($addon);
        }
    }

    // ── Service execution ────────────────────────────────────────────────

    // Serves both "Start Early" and a normal on-time start — identical
    // backend contract either way (rule 8's explicit-choice requirement is
    // satisfied by the click itself; the frontend alone decides when to
    // surface an early-start affordance). Requires at least one Assigned
    // therapist first (rule 9) and re-validates every assigned
    // therapist/room in real time to catch drift from an earlier service
    // running long (rule 14).
    public function startService(User $user, string $appointmentServiceUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentServiceUuid) {
            $service = AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $appointmentServiceUuid)
                ->lockForUpdate()
                ->with('therapistAssignments', 'appointment')
                ->firstOrFail();

            if ($refusal = $this->addonFollows($service, 'starts')) {
                return $refusal;
            }

            if ($stale = StaleVisit::refusal($service->appointment)) {
                return $stale;
            }

            // One sitting begins as one: the services this client has with the same
            // staff in the same facility start together (and are completed together).
            // A service of different staff is never started with it. All or none: a
            // refusal for any of them rolls the whole start back.
            $mates = $this->sittingMates($service);
            $together = $mates->pluck('id')->push($service->id)->all();

            $result = $this->startServiceInternal($service, $together);
            if (! $result['ok']) {
                return response()->json(['message' => $result['reason']], 422);
            }
            foreach ($mates as $mate) {
                $mate->setRelation('appointment', $service->appointment);
                $matesResult = $this->startServiceInternal($mate, $together);
                throw_if(! $matesResult['ok'], ActionRefused::class, $matesResult['reason']);
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($service->appointment->uuid));
        });
    }

    // Settings → Staff & Permissions → Digital check-in: with attendance
    // tracking on, a therapist has to be timed in (and not yet out) today
    // before a service of theirs can start. Assigning ahead of time is
    // unaffected — this only gates the moment the service begins.
    private function checkInProblem(Appointment $appointment, array $staffIds): ?string
    {
        if (! $staffIds) {
            return null;
        }

        // Someone removed or switched off since being assigned does not perform it
        // (and, being gone, would not show as "not timed in" either).
        $gone = Staff::withTrashed()->whereIn('id', $staffIds)->get(['id', 'first_name', 'last_name', 'status', 'deleted_at'])
            ->filter(fn (Staff $s) => $s->trashed() || $s->status === StaffStatus::Inactive->value);
        if ($gone->isNotEmpty()) {
            $names = $gone->map(fn (Staff $s) => trim("{$s->first_name} {$s->last_name}"))->implode(', ');

            return "{$names} ".($gone->count() === 1 ? 'is' : 'are').' no longer on the staff. Choose someone else for this service.';
        }

        $appointment->loadMissing('branch.business.settings');
        $policy = $appointment->branch?->business?->settings?->section('staff_policy')
            ?? SpaBusinessSetting::DEFAULTS['staff_policy'];
        if (! ($policy['attendance_tracking'] ?? true) || ! ($policy['require_check_in'] ?? true)) {
            return null;
        }

        $present = Attendance::whereIn('staff_id', $staffIds)
            ->where('attendance_date', BusinessDay::forBranch($appointment->spa_branch_id))
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->pluck('staff_id')
            ->all();

        $missing = Staff::whereIn('id', array_diff($staffIds, $present))->get(['first_name', 'last_name']);
        if ($missing->isEmpty()) {
            return null;
        }

        $names = $missing->map(fn (Staff $s) => trim("{$s->first_name} {$s->last_name}"))->implode(', ');

        return $missing->count() === 1
            ? "{$names} hasn't timed in for today yet. Time them in on Attendance before starting this service."
            : "{$names} haven't timed in for today yet. Time them in on Attendance before starting this service.";
    }

    // Shared by startService() (explicit front-desk click) and
    // completeService()'s same-therapist auto-continue below — one place
    // for what "actually starting a service" means (guards + transition),
    // so the automatic path can't drift from the manual one.
    //
    // $together: the ids of the services starting in this same sitting (the same
    // staff in the same facility): they run side by side, so none of them counts as
    // the others' busy staff or as another service of the visit in the way.
    public function startServiceInternal(AppointmentServiceItem $service, array $together = []): array
    {
        if ($service->status !== AppointmentServiceStatus::Pending->value) {
            return ['ok' => false, 'reason' => 'This service has already started, completed, or been cancelled.'];
        }
        // Nothing starts for a client who is not here: check them in first.
        if (! in_array($service->appointment->status, [AppointmentStatus::CheckedIn->value, AppointmentStatus::InService->value], true)) {
            return ['ok' => false, 'reason' => 'Check the client in before starting their service.'];
        }

        // What it needs depends on its kind (ServiceKind): a therapist,
        // unless nobody performs it (a facility-based service — a sauna).
        // And a facility, when the service needs one (always for a
        // facility-based one, else the owner's tick) — except on a home
        // visit, which has none.
        $kind = $this->kindOf($service);
        $activeAssignments = $service->therapistAssignments->where('assignment_status', AssignmentStatus::Assigned->value);
        if (ServiceKind::needsTherapist($kind) && $activeAssignments->whereNotNull('staff_id')->isEmpty()) {
            return ['ok' => false, 'reason' => 'Assign at least one staff member before starting this service.'];
        }
        // Done by several staff at once: everyone starts together, so every part needs someone.
        if (ServiceKind::needsTherapist($kind) && ($missing = ServiceParts::missing($service))->isNotEmpty()) {
            return ['ok' => false, 'reason' => 'Pick who does '.ServiceParts::names($missing).' of '.($service->displayName() ?? 'this service').' before starting it.'];
        }
        if ($service->needsFacility() && ! $service->appointment->isHomeService() && $activeAssignments->whereNotNull('facility_id')->isEmpty()) {
            return ['ok' => false, 'reason' => 'Choose a facility for '.($service->displayName() ?? 'this service').' before starting it.'];
        }

        // One client, one place: while another service of this visit is in
        // progress, this one may only start beside it — same room (or no
        // room on either), different therapists. The visit is locked so two
        // Starts on it are judged one after the other.
        if ($problem = $this->sameVisitStartProblem($service, $activeAssignments, $together)) {
            return ['ok' => false, 'reason' => $problem];
        }

        // A procedure-based service may run beside this client's other
        // procedure-based services with the same therapist (the rule above
        // let it through), so those don't count as the therapist being busy.
        $alongside = $kind === ServiceKind::PROCEDURE
            ? AppointmentServiceItem::where('appointment_id', $service->appointment_id)
                ->where('id', '!=', $service->id)
                ->where('status', AppointmentServiceStatus::InProgress->value)
                ->with('serviceVariant.service')
                ->get()
                // An add-on counts as its main service does: it is the same
                // therapist's same sitting.
                ->filter(fn (AppointmentServiceItem $other) => $this->kindOf($other->isAddon() ? ($other->parent ?? $other) : $other) === ServiceKind::PROCEDURE)
                ->pluck('id')->all()
            : [];
        $alongside = array_values(array_unique(array_merge($alongside, $together)));

        // Staff held for another client's reservation that hasn't arrived yet
        // (App\Support\Visits\ReservationHold): this service may not run into it.
        $grace = $service->appointment->branch
            ? (int) (BookingPolicy::for($service->appointment->branch)['reservation_grace_minutes'] ?? ReservationHold::DEFAULT_GRACE_MINUTES)
            : ReservationHold::DEFAULT_GRACE_MINUTES;
        $length = $service->lengthMinutes() ?? 30;

        // Still waiting in the line (nothing of theirs started yet): nobody ahead
        // of them may be waiting for the same staff member or facility.
        $queue = $service->appointment->queue;
        $waiting = $queue && in_array($queue->queue_status, [QueueStatus::Waiting->value, QueueStatus::Called->value], true);
        $walkInsFirst = $service->appointment->branch ? (bool) (BookingPolicy::for($service->appointment->branch)['walk_ins_first_priority'] ?? false) : false;

        foreach ($activeAssignments as $assignment) {
            if ($waiting && ($ahead = $this->availabilityService->aheadInLine($assignment->staff_id, $assignment->facility_id, $service->appointment, $walkInsFirst))) {
                return ['ok' => false, 'reason' => $ahead];
            }
            // Room-only rows (staff_id null, rule 7) have no therapist to
            // check busy-ness for — only the facility check below applies.
            if ($assignment->staff_id) {
                // Off today (leave, sick, absent — Employees or the desk's "On leave"): they can't
                // start a client, even when assigned before the leave was set. Give it to someone else.
                $today = BusinessDay::forBranch((int) $service->appointment->spa_branch_id);
                if ($away = $this->availabilityService->leaveOn($assignment->staff_id, $today)) {
                    $who = \App\Models\Staff::find($assignment->staff_id);
                    return ['ok' => false, 'reason' => trim(($who?->first_name ?? '') . ' ' . ($who?->last_name ?? '')) . " is off today ({$away}). Give the service to someone else."];
                }
                $busy = $this->availabilityService->isStaffCurrentlyBusy($assignment->staff_id, $assignment->id, $alongside);
                if (! $busy['ok']) {
                    return ['ok' => false, 'reason' => $busy['reason']];
                }
                if ($held = $this->availabilityService->reservedElsewhere($assignment->staff_id, $service->appointment_id, now(), $length, $grace)) {
                    return ['ok' => false, 'reason' => $held];
                }
                // Here early for their booking: whoever is booked with the same
                // staff member before them, and is here too, is served first.
                if ($first = $this->availabilityService->bookedFirst($assignment->staff_id, $service->appointment)) {
                    return ['ok' => false, 'reason' => $first];
                }
            }

            if ($assignment->facility_id) {
                // Put under maintenance after it was picked: not started in (the
                // client in it now, if any, is left to finish).
                if ($outOfUse = $this->availabilityService->facilityOutOfUse($assignment->facility_id, $service->appointment_id)) {
                    return ['ok' => false, 'reason' => $outOfUse];
                }
                // In use by ANOTHER client's service — this visit's own service
                // in the room is the "run together" case allowed just above.
                $occupied = $this->availabilityService->isFacilityCurrentlyOccupied($assignment->facility_id, $assignment->id, $service->appointment_id);
                if (! $occupied['ok']) {
                    return ['ok' => false, 'reason' => $occupied['reason']];
                }
            }
        }

        if ($problem = $this->checkInProblem($service->appointment, $activeAssignments->pluck('staff_id')->filter()->all())) {
            return ['ok' => false, 'reason' => $problem];
        }

        // Nobody performs it and no room was picked: when it started and
        // ended is still kept, on a row with neither (the start time of
        // every service lives on its assignment).
        if ($activeAssignments->isEmpty()) {
            $activeAssignments = $activeAssignments->push($this->therapistAssignmentRepository->openSpace($service->id));
        }

        $now = now();
        foreach ($activeAssignments as $assignment) {
            $assignment->update(['assignment_status' => AssignmentStatus::InProgress->value, 'started_at' => $now]);
        }
        $service->update(['status' => AppointmentServiceStatus::InProgress->value]);
        // Its add-ons start with it, on the same therapist(s) and room.
        $this->startAddons($service, $now);

        $appointment = $service->appointment;

        // First service on this appointment to actually start moves the
        // header from Checked In to In Service — a real stored status now,
        // replacing the old derived checked_in_in_service label. Stays In
        // Service (doesn't bounce back) if a later service on the same
        // appointment starts too.
        if ($appointment->canTransitionTo(AppointmentStatus::InService->value)) {
            $appointment->update(['status' => AppointmentStatus::InService->value]);
        }

        // served_at anchors the whole visit's "Estimated finish" (servedAt +
        // totalDurationMinutes, see QueueStatusPanel.vue/QueueView.vue) — set
        // it only the first time the ticket actually enters Serving. A later
        // service on the same visit starting (auto-continue or manual) must
        // not push it forward, or that estimate would silently inflate by
        // however long the earlier service(s) already took.
        if ($appointment->queue && $appointment->queue->queue_status !== QueueStatus::Serving->value) {
            $this->queueRepository->updateStatus($appointment->queue, QueueStatus::Serving->value, ['served_at' => $now]);
        }

        return ['ok' => true, 'reason' => null];
    }

    // ── Pause and resume (App\Support\Visits\ServicePause) ───────────────

    // The client steps out: the clock stops, the therapist and the room stay held.
    // The services of the same sitting (same staff, same room) pause with it.
    public function pauseService(User $user, string $appointmentServiceUuid, ?string $reason = null, ?Request $request = null)
    {
        return $this->pauseOrResume($user, $appointmentServiceUuid, true, $reason, $request);
    }

    public function resumeService(User $user, string $appointmentServiceUuid, ?Request $request = null)
    {
        return $this->pauseOrResume($user, $appointmentServiceUuid, false, null, $request);
    }

    private function pauseOrResume(User $user, string $appointmentServiceUuid, bool $pause, ?string $reason, ?Request $request)
    {
        if (! ServicePause::ready()) {
            return response()->json(['message' => 'Pausing a service is not available yet.'], 422);
        }
        if (! $this->spaBusinessRepository->findForUser($user)) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }
        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentServiceUuid, $pause, $reason, $user, $request) {
            $service = AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $appointmentServiceUuid)
                ->lockForUpdate()
                ->with('therapistAssignments', 'appointment')
                ->firstOrFail();

            if ($refusal = $this->addonFollows($service, $pause ? 'is paused' : 'is resumed')) {
                return $refusal;
            }
            if ($stale = StaleVisit::refusal($service->appointment)) {
                return $stale;
            }
            if ($service->status !== AppointmentServiceStatus::InProgress->value) {
                return response()->json(['message' => $pause ? 'Only a service in progress can be paused.' : 'Only a service in progress can be resumed.'], 422);
            }
            if ($pause && $service->isOpenStay()) {
                return response()->json(['message' => 'An open stay has no clock to stop: the client stays as long as they like.'], 422);
            }
            if ($pause === ServicePause::isPaused($service)) {
                return response()->json(['message' => $pause ? 'This service is already paused.' : 'This service is not paused.'], 422);
            }

            $now = now();
            // Pausing: this service and the ones of its sitting (same staff, same room).
            // Resuming: every service of the visit that was paused with it (the same moment), even
            // one moved to another room or given to someone else while paused — none is left behind.
            $lines = $pause
                ? $this->sittingMates($service, AppointmentServiceStatus::InProgress->value)->prepend($service)
                    ->filter(fn (AppointmentServiceItem $line) => ! ServicePause::isPaused($line))
                : AppointmentServiceItem::where('appointment_id', $service->appointment_id)
                    ->where('status', AppointmentServiceStatus::InProgress->value)
                    ->where('paused_at', $service->paused_at)
                    ->lockForUpdate()
                    ->get();
            foreach ($lines as $line) {
                $pause ? $line->update(['paused_at' => $now]) : ServicePause::end($line, $now);
                app(AuditLogRepository::class)->record(
                    $user->id, 'appointment_services', $line->id, 'Update',
                    ['service' => $line->displayName(), 'clock' => $pause ? 'Running' : 'Paused'],
                    array_filter(['service' => $line->displayName(), 'clock' => $pause ? 'Paused' : 'Running', 'reason' => $pause ? $reason : null, 'paused_minutes' => $pause ? null : (int) $line->fresh()->paused_minutes]),
                    $request,
                );
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($service->appointment->uuid));
        });
    }

    // The services of this visit that are one sitting with $service: the same
    // staff in the same facility, in the same state ($status: 'Pending' to start
    // them, 'In Progress' to complete them). Needs staff and a facility on
    // $service — without a room nothing says they are in one place. Add-ons are
    // not listed: they ride with their own main service.
    public function sittingMates(AppointmentServiceItem $service, string $status = AppointmentServiceStatus::Pending->value): Collection
    {
        $assigned = $status === AppointmentServiceStatus::Pending->value ? AssignmentStatus::Assigned->value : AssignmentStatus::InProgress->value;
        $service->loadMissing('therapistAssignments');
        $mine = $service->therapistAssignments->where('assignment_status', $assigned);
        $staff = $mine->pluck('staff_id')->filter()->unique()->sort()->values()->all();
        $room = $mine->pluck('facility_id')->filter()->first();
        if (! $staff || ! $room) {
            return collect();
        }

        return AppointmentServiceItem::where('appointment_id', $service->appointment_id)
            ->where('id', '!=', $service->id)
            ->where('status', $status)
            ->when(Addon::ready(), fn ($q) => $q->whereNull('parent_appointment_service_id'))
            ->with('therapistAssignments')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(function (AppointmentServiceItem $other) use ($assigned, $staff, $room) {
                $theirs = $other->therapistAssignments->where('assignment_status', $assigned);

                return $theirs->pluck('staff_id')->filter()->unique()->sort()->values()->all() == $staff
                    && (int) $theirs->pluck('facility_id')->filter()->first() === (int) $room;
            })
            ->values();
    }

    // Why this service can't start beside the visit's other services that are
    // in progress, or null (App\Support\Visits\ServiceStartRules holds the rules).
    private function sameVisitStartProblem(AppointmentServiceItem $service, $activeAssignments, array $together = []): ?string
    {
        Appointment::whereKey($service->appointment_id)->lockForUpdate()->first();

        $running = AppointmentServiceItem::where('appointment_id', $service->appointment_id)
            ->where('id', '!=', $service->id)
            ->when($together, fn ($q) => $q->whereNotIn('id', $together))
            ->where('status', AppointmentServiceStatus::InProgress->value)
            // An add-on is part of its main service, which is in this list.
            ->when(Addon::ready(), fn ($q) => $q->whereNull('parent_appointment_service_id'))
            ->with([
                'serviceVariant.service',
                'therapistAssignments' => fn ($q) => $q->where('assignment_status', AssignmentStatus::InProgress->value)->with('staff', 'facility'),
            ])
            ->orderBy('sort_order')
            ->get();

        if ($running->isEmpty()) {
            return null;
        }

        $activeAssignments->loadMissing('staff');
        $staffOf = fn ($assignments) => $assignments
            ->filter(fn ($a) => $a->staff_id)
            ->mapWithKeys(fn ($a) => [$a->staff_id => trim("{$a->staff?->first_name} {$a->staff?->last_name}") ?: 'The staff member'])
            ->all();

        return ServiceStartRules::problem(
            [
                'staff' => $staffOf($activeAssignments),
                'room' => $activeAssignments->pluck('facility_id')->filter()->first(),
                'kind' => $this->kindOf($service),
            ],
            $running->map(function (AppointmentServiceItem $other) use ($staffOf) {
                $withRoom = $other->therapistAssignments->first(fn ($a) => $a->facility_id);

                return [
                    'name' => $other->displayName() ?? 'another service',
                    'staff' => $staffOf($other->therapistAssignments),
                    'room' => $withRoom?->facility_id,
                    'room_name' => $withRoom?->facility?->name,
                    'kind' => $this->kindOf($other),
                ];
            })->all(),
        );
    }

    // Rejects anything not currently In Progress — the duplicate-completion
    // guard (rule 14). Flips the queue ticket to Completed only once no
    // other service on the appointment is still Pending/In Progress.
    public function completeService(User $user, string $appointmentServiceUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentServiceUuid) {
            $service = AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
                ->where('uuid', $appointmentServiceUuid)
                ->lockForUpdate()
                ->with('therapistAssignments', 'appointment')
                ->firstOrFail();

            if ($stale = StaleVisit::refusal($service->appointment)) {
                return $stale;
            }

            if ($service->status !== AppointmentServiceStatus::InProgress->value) {
                return response()->json(['message' => 'Only a service currently in progress can be completed.'], 422);
            }
            if ($refusal = $this->addonFollows($service, 'is completed')) {
                return $refusal;
            }

            $now = now();
            // One sitting finishes as one: the services this client has with the
            // same staff in the same facility that are running with this one.
            $completedStaffIds = [];
            foreach ($this->sittingMates($service, AppointmentServiceStatus::InProgress->value)->prepend($service) as $line) {
                $completedStaffIds = array_merge($completedStaffIds, $this->completeLine($line, $now));
            }
            $completedStaffIds = array_values(array_unique($completedStaffIds));

            $appointment = $service->appointment;

            // Same therapist continuing straight on to the client's next
            // service needs no separate "Start Service" click — they never
            // actually left. That is the client's next waiting service that
            // THEY are assigned to — one in a different (or no) facility: the
            // ones in the same facility were started together with this one
            // and finished with it (sittingMates). A service of a different
            // therapist is never started by this: it is left Pending and
            // started on its own, as before.
            if ($completedStaffIds) {
                $nextService = $appointment->services()
                    ->where('status', AppointmentServiceStatus::Pending->value)
                    // Never an add-on: it starts with its main service.
                    ->when(Addon::ready(), fn ($q) => $q->whereNull('parent_appointment_service_id'))
                    ->whereHas('therapistAssignments', fn ($q) => $q->where('assignment_status', AssignmentStatus::Assigned->value)->whereIn('staff_id', $completedStaffIds))
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->with('therapistAssignments')
                    ->first();

                if ($nextService) {
                    $this->startServiceInternal($nextService);
                }
            }

            $stillActive = $appointment->services()->whereIn('status', [AppointmentServiceStatus::Pending->value, AppointmentServiceStatus::InProgress->value])->exists();

            if (! $stillActive && $appointment->queue) {
                $this->queueRepository->updateStatus($appointment->queue, QueueStatus::Completed->value, ['completed_at' => $now]);
            }
            // A client covered by another client's shared package, with nothing of their own to
            // pay: closed now, never asked to pay (only the client who bought it pays).
            if (! $stillActive) {
                $this->closeCoveredVisit($appointment);
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // Completes one running service: its add-on still waiting (none should
    // be) joins first, its staff and room are released, what each earned is
    // fixed under today's Staff Policies (CommissionCalculator::freeze), and its
    // add-ons are completed with it. Returns the staff who were doing it.
    public function completeLine(AppointmentServiceItem $service, $now): array
    {
        // Completed while paused: the pause ends first, so the time used leaves it out.
        if (ServicePause::isPaused($service)) {
            ServicePause::end($service, Carbon::parse($now));
            $service->load('therapistAssignments');
        }
        $this->startAddons($service, $now);
        $staffIds = $service->therapistAssignments
            ->where('assignment_status', AssignmentStatus::InProgress->value)
            ->pluck('staff_id')
            ->filter()
            ->all();
        foreach ($service->therapistAssignments->where('assignment_status', AssignmentStatus::InProgress->value) as $assignment) {
            $assignment->update(['assignment_status' => AssignmentStatus::Completed->value, 'completed_at' => $now]);
        }
        $service->update(['status' => AppointmentServiceStatus::Completed->value]);
        app(CommissionCalculator::class)->freeze($service);
        $this->completeAddons($service, $now);

        return $staffIds;
    }

    // A package service that was already done when its sitting was paused and handed to another
    // therapist (VisitAssignmentService::changeTherapist): completed as it really ran — from $from
    // to $to, before the pause, which is no part of it — so whoever did it is the one paid.
    public function completeFinishedLine(AppointmentServiceItem $service, Carbon $from, Carbon $to): void
    {
        $service->update(['paused_at' => null]);
        $this->startAddons($service, $from);
        $service->load('therapistAssignments');
        foreach ($service->therapistAssignments->where('assignment_status', AssignmentStatus::InProgress->value) as $assignment) {
            $assignment->update(['assignment_status' => AssignmentStatus::Completed->value, 'started_at' => $from, 'completed_at' => $to]);
        }
        $service->update(['status' => AppointmentServiceStatus::Completed->value]);
        app(CommissionCalculator::class)->freeze($service);
        $this->completeAddons($service, $to);
    }

    // A visit covered by another client's shared package (Client B of a Couples Massage) with
    // nothing of its own to pay: once every service is done, a ₱0 bill is made and settled, so the
    // visit completes and never waits for a payment. Only the client who bought the package pays.
    // True when it was closed this way. Runs inside the caller's transaction.
    public function closeCoveredVisit(Appointment $appointment): bool
    {
        $appointment = $appointment->fresh();
        if (! $appointment
            || $this->billingRepository->findForAppointment($appointment->id)
            || $appointment->services()->whereNotIn('status', [AppointmentServiceStatus::Completed->value, AppointmentServiceStatus::Cancelled->value])->exists()
            || ! $appointment->services()->where('status', AppointmentServiceStatus::Completed->value)->exists()
            || ! SharedPackages::closesFree((float) $appointment->total_amount, SharedPackages::isCovered($appointment))) {
            return false;
        }

        $billing = $this->billingRepository->create([
            'appointment_id' => $appointment->id,
            'spa_business_id' => $appointment->branch->spa_business_id,
            'spa_branch_id' => $appointment->spa_branch_id,
            'billing_type' => 'Appointment',
            'billing_number' => $this->billingRepository->generateBillingNumber(),
            'subtotal' => 0,
            'amount' => 0,
            'status' => BillingStatus::Pending->value,
            'issued_at' => now(),
        ]);
        $this->billingService->settle($billing->fresh());

        return true;
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
