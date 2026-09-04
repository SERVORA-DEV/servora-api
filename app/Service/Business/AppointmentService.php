<?php

namespace App\Service\Business;

use App\Http\Resources\AppointmentResource;
use App\Http\Resources\PaymentResource;
use App\Models\Appointment;
use App\Models\AppointmentPackage;
use App\Models\AppointmentServiceItem;
use App\Models\Attendance;
use App\Models\Billing;
use App\Models\BranchPackage;
use App\Models\BranchSchedule;
use App\Models\BranchService;
use App\Models\Facility;
use App\Models\Package;
use App\Models\ServiceVariant;
use App\Models\Staff;
use App\Models\TherapistAssignment;
use App\Models\User;
use Carbon\Carbon;
use App\Repository\Business\AppointmentRepository;
use App\Repository\Business\AppointmentServiceRepository;
use App\Repository\Business\ClientRepository;
use App\Repository\Business\QueueRepository;
use App\Repository\Business\SpaBranchRepository;
use App\Repository\Business\TherapistAssignmentRepository;
use App\Repository\BillingRepository;
use App\Repository\PaymentRepository;
use App\Repository\SpaBusinessRepository;
use Illuminate\Support\Facades\DB;

// Orchestrates the full appointment lifecycle end to end: creation,
// check-in, service/package selection, per-service multi-therapist and room
// assignment, queueing, service execution, cancellation/no-show, and
// billing/payment handoff. Billing/payment steps stay here rather than in a
// separate service class since they're just later steps of the same
// lifecycle and reuse the existing shared BillingRepository/PaymentRepository
// as-is (no parallel billing system). Queue *mutations* also stay here
// (QueueService is read-only) — every write to an appointment's state goes
// through this one class, which is what makes the row-locked transactional
// guards below (start/complete/bill) actually meaningful.
class AppointmentService
{
    private AppointmentRepository $appointmentRepository;
    private AppointmentServiceRepository $appointmentServiceRepository;
    private TherapistAssignmentRepository $therapistAssignmentRepository;
    private QueueRepository $queueRepository;
    private ClientRepository $clientRepository;
    private ClientService $clientService;
    private SpaBusinessRepository $spaBusinessRepository;
    private SpaBranchRepository $spaBranchRepository;
    private AppointmentAvailabilityService $availabilityService;
    private BillingRepository $billingRepository;
    private PaymentRepository $paymentRepository;
    private AttendanceStatusCalculator $attendanceStatusCalculator;

    public function __construct(
        AppointmentRepository $appointmentRepository,
        AppointmentServiceRepository $appointmentServiceRepository,
        TherapistAssignmentRepository $therapistAssignmentRepository,
        QueueRepository $queueRepository,
        ClientRepository $clientRepository,
        ClientService $clientService,
        SpaBusinessRepository $spaBusinessRepository,
        SpaBranchRepository $spaBranchRepository,
        AppointmentAvailabilityService $availabilityService,
        BillingRepository $billingRepository,
        PaymentRepository $paymentRepository,
        AttendanceStatusCalculator $attendanceStatusCalculator,
    ) {
        $this->appointmentRepository = $appointmentRepository;
        $this->appointmentServiceRepository = $appointmentServiceRepository;
        $this->therapistAssignmentRepository = $therapistAssignmentRepository;
        $this->queueRepository = $queueRepository;
        $this->clientRepository = $clientRepository;
        $this->clientService = $clientService;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->spaBranchRepository = $spaBranchRepository;
        $this->availabilityService = $availabilityService;
        $this->billingRepository = $billingRepository;
        $this->paymentRepository = $paymentRepository;
        $this->attendanceStatusCalculator = $attendanceStatusCalculator;
    }

    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
    }

    // ── Listing / detail ────────────────────────────────────────────────

    public function listAppointments(User $user, array $filters, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $collection = $this->appointmentRepository->paginateForBranches($this->branchIds($user), $filters, $perPage);
        return AppointmentResource::collection($collection);
    }

    // Backs the owner/manager "Billing & Payments" page — every appointment
    // billing across the caller's branches (not the subscription billings
    // System > Transactions already covers). See BillingListResource for
    // the flattened shape this returns.
    public function listBillings(User $user, array $filters, int $perPage = 15)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $collection = $this->billingRepository->paginateAppointmentBillingsForBranches($this->branchIds($user), $filters, $perPage);
        return \App\Http\Resources\BillingListResource::collection($collection);
    }

    public function getAppointment(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        return new AppointmentResource($appointment);
    }

    // ── Creation ─────────────────────────────────────────────────────────

    // Minimum required payload: spa_branch_uuid + appointment_date +
    // appointment_time (+ client_uuid xor client{...}) — services and
    // requested_therapist_uuid are optional (rule 1). A failed requested-
    // therapist assignment never fails the whole creation — it's surfaced
    // as a warning instead (rule 6: "clearly show unavailable... continue
    // without assigning one yet").
    public function createAppointment(User $user, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branches = $this->spaBusinessRepository->branchesForUser($user);
        $branchIds = $branches->pluck('id')->all();

        return DB::transaction(function () use ($business, $branches, $branchIds, $payload) {
            if (! empty($payload['spa_branch_uuid'])) {
                $branch = $this->spaBranchRepository->findByUuidForBranches($payload['spa_branch_uuid'], $branchIds);
            } elseif ($branches->count() === 1) {
                // front_officer/manager: exactly one accessible branch,
                // their UI has no branch picker at all.
                $branch = $branches->first();
            } else {
                return response()->json(['message' => 'spa_branch_uuid is required.'], 422);
            }

            $scheduleCheck = $this->availabilityService->branchIsOpen($branch->id, $payload['appointment_date'], $payload['appointment_time']);
            if (! $scheduleCheck['ok']) {
                return response()->json(['message' => $scheduleCheck['reason']], 422);
            }

            $client = ! empty($payload['client_uuid'])
                ? $this->clientRepository->findByUuidForBusiness($payload['client_uuid'], $business->id)
                : $this->clientService->findOrCreate($business->id, $payload['client'] ?? []);

            $appointmentType = $payload['appointment_type'] ?? 'Reservation';
            // A walk-in has already arrived by definition — skip the
            // Scheduled/manual-check-in step entirely and land straight on
            // Checked In + queued, mirroring what checkIn() would otherwise
            // do a moment later.
            $isWalkIn = $appointmentType === 'Walk-in';

            $appointment = $this->appointmentRepository->create([
                'spa_branch_id' => $branch->id,
                'client_id' => $client->id,
                'appointment_number' => $this->appointmentRepository->generateAppointmentNumber(),
                'appointment_date' => $payload['appointment_date'],
                'appointment_time' => $payload['appointment_time'],
                'appointment_type' => $appointmentType,
                'source' => $payload['source'] ?? 'Front Desk',
                'status' => $isWalkIn ? Appointment::STATUS_CHECKED_IN : Appointment::STATUS_SCHEDULED,
                'check_in_at' => $isWalkIn ? now() : null,
                'remarks' => $payload['remarks'] ?? null,
            ]);

            $warnings = [];

            foreach (array_values($payload['services'] ?? []) as $index => $item) {
                $service = $this->addServiceInternal($appointment, $branch->id, $item, null, $index + 1);

                if (! empty($payload['requested_therapist_uuid'])) {
                    $staff = $this->resolveStaffForBranch($payload['requested_therapist_uuid'], $branch->id);

                    if (! $staff) {
                        $warnings[] = 'Requested therapist not found or inactive at this branch.';
                    } else {
                        $service->load('serviceVariant');
                        $result = $this->assignTherapistInternal($service, $appointment, $staff);
                        if (! $result['ok']) {
                            $warnings[] = $result['reason'];
                        }
                    }
                }
            }

            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

            if ($isWalkIn) {
                $this->addToQueueInternal($appointment);
            }

            $resource = new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));

            return $warnings ? $resource->additional(['warnings' => $warnings]) : $resource;
        });
    }

    // ── Status transitions ──────────────────────────────────────────────
    // No client-confirmation step exists in this workflow — an appointment
    // lands on Scheduled at creation and moves directly to Checked In.

    // Check-in flips the header status, records check_in_at, and immediately
    // places the appointment on the queue (addToQueueInternal is a no-op if
    // it's already queued — see createAppointment()'s walk-in path, which
    // queues at creation time instead).
    public function checkIn(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $uuid) {
            $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $branchIds);

            if (! $appointment->canTransitionTo(Appointment::STATUS_CHECKED_IN)) {
                return response()->json(['message' => 'Only a scheduled appointment can be checked in.'], 422);
            }

            $appointment->update(['status' => Appointment::STATUS_CHECKED_IN, 'check_in_at' => now()]);
            $this->addToQueueInternal($appointment);

            return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
        });
    }

    public function markNoShow(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));

        if (! $appointment->canTransitionTo(Appointment::STATUS_NO_SHOW)) {
            return response()->json(['message' => 'Only a scheduled appointment that has not been checked in can be marked as no-show.'], 422);
        }

        $appointment->update(['status' => Appointment::STATUS_NO_SHOW]);

        return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
    }

    // ── Queue actions (front-desk manual control, not lifecycle side
    // effects) ────────────────────────────────────────────────────────────
    // Unlike every other queue write in this class (check-in/start/complete/
    // cancel all move the ticket as a side effect of an appointment status
    // change), calling/skipping/recalling is a deliberate front-desk action
    // on the ticket itself. Still routed through here rather than
    // QueueService (which stays read-only, see this class's own opening
    // comment) to keep the "exactly one write path" rule intact.
    public function callQueue(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        $queue = $appointment->queue;

        if (! $queue || $queue->queue_status !== 'Waiting') {
            return response()->json(['message' => 'Only a waiting client can be called.'], 422);
        }

        $this->queueRepository->updateStatus($queue, 'Called', ['called_at' => now()]);

        return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
    }

    public function skipQueue(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        $queue = $appointment->queue;

        if (! $queue || ! in_array($queue->queue_status, ['Waiting', 'Called'], true)) {
            return response()->json(['message' => 'Only a waiting or called client can be skipped.'], 422);
        }

        $this->queueRepository->updateStatus($queue, 'Skipped');

        return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
    }

    // Brings a Called/Skipped ticket back into the active line — e.g. a
    // skipped client shows back up, or Call was a misclick. Always lands
    // back on Waiting; queue order stays FIFO by created_at
    // (QueueRepository::listForBranchDate), so recalling doesn't move the
    // ticket to the back of the line, it just clears the Called/Skipped flag.
    public function recallQueue(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        $queue = $appointment->queue;

        if (! $queue || ! in_array($queue->queue_status, ['Called', 'Skipped'], true)) {
            return response()->json(['message' => 'Only a called or skipped client can be recalled to the queue.'], 422);
        }

        $this->queueRepository->updateStatus($queue, 'Waiting', ['called_at' => null]);

        return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
    }

    // Blocked once any service has started or finished (rule 4: cancellable
    // pre-start only, including while waiting/queued). Cascades cancel to
    // every still-pending service, their still-assigned therapists, and the
    // queue ticket — never deletes any of them, preserving history.
    public function cancelAppointment(User $user, string $uuid, ?string $reason = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $uuid, $reason) {
            $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $branchIds);

            if (! $appointment->canTransitionTo(Appointment::STATUS_CANCELLED)) {
                return response()->json(['message' => 'This appointment can no longer be cancelled.'], 422);
            }

            $blocked = $appointment->services()->whereIn('status', ['In Progress', 'Completed'])->exists();
            if ($blocked) {
                return response()->json(['message' => 'Cannot cancel an appointment with a service already in progress or completed.'], 422);
            }

            $serviceIds = $appointment->services()->pluck('id');

            // Bulk query-builder update, not individual model saves — doesn't
            // fire AppointmentServiceItem's model events, so the totals
            // recalculation below can't be skipped in favor of relying on
            // that safety net alone (see AppointmentServiceItem::booted()).
            $appointment->services()->where('status', 'Pending')->update(['status' => 'Cancelled']);

            TherapistAssignment::whereIn('appointment_service_id', $serviceIds)
                ->where('assignment_status', 'Assigned')
                ->update(['assignment_status' => 'Cancelled']);

            if ($appointment->queue) {
                $this->queueRepository->updateStatus($appointment->queue, 'Cancelled');
            }

            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

            $appointment->update([
                'status' => Appointment::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
        });
    }

    // Not a status transition — appointment_date/time simply change, so
    // this is gated by an explicit allow-list rather than canTransitionTo().
    // Only Scheduled/Checked-In are allowed: In Service is blocked outright
    // (rule: reschedule is blocked once in-service, with no override — the
    // in-progress/completed guard cancelAppointment() already enforces
    // means cancel-then-reschedule is never actually reachable once
    // in_service, so this simply refuses rather than offering a dead-end
    // "cancel first" path), and Completed/Cancelled/No Show are terminal.
    //
    // Does not re-validate existing therapist/room assignments against the
    // new time — reservations were never time-blocked in the first place
    // (see assignTherapistInternal()/assignRoomInternal()), so moving the
    // appointment doesn't need to re-run a check that no longer exists.
    public function rescheduleAppointment(User $user, string $uuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $uuid, $payload) {
            $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $branchIds);

            if (! in_array($appointment->status, [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CHECKED_IN], true)) {
                return response()->json(['message' => 'Only a scheduled or checked-in appointment can be rescheduled.'], 422);
            }

            $scheduleCheck = $this->availabilityService->branchIsOpen($appointment->spa_branch_id, $payload['appointment_date'], $payload['appointment_time']);
            if (! $scheduleCheck['ok']) {
                return response()->json(['message' => $scheduleCheck['reason']], 422);
            }

            // Moving a checked-in appointment to a new date/time means the
            // client hasn't actually checked in for that new slot yet — revert
            // to Scheduled and clear check_in_at, and void the queue ticket
            // check-in created (same call cancelAppointment() already uses to
            // void one), so it doesn't linger in the old date's queue.
            $wasCheckedIn = $appointment->status === Appointment::STATUS_CHECKED_IN;

            if ($wasCheckedIn && $appointment->queue) {
                $this->queueRepository->updateStatus($appointment->queue, 'Cancelled');
            }

            // A walk-in is same-day by definition ("this client walked in
            // right now") — pushed out to a future date, it's no longer that,
            // it's something booked ahead of time, i.e. a Reservation.
            // Same-day reschedules (just a different time) stay a walk-in.
            $becomesReservation = $appointment->appointment_type === 'Walk-in'
                && $payload['appointment_date'] > now()->toDateString();

            $appointment->update([
                'appointment_date' => $payload['appointment_date'],
                'appointment_time' => $payload['appointment_time'],
                'remarks' => $payload['reason'] ?? $appointment->remarks,
                'status' => $wasCheckedIn ? Appointment::STATUS_SCHEDULED : $appointment->status,
                'check_in_at' => $wasCheckedIn ? null : $appointment->check_in_at,
                'appointment_type' => $becomesReservation ? 'Reservation' : $appointment->appointment_type,
            ]);

            return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
        });
    }

    // ── Service / package selection ─────────────────────────────────────

    // Same code path for the initial post-check-in service selection and
    // for "add an additional service to an active appointment" (rule 11) —
    // there is nothing appointment-state-specific about adding a line item
    // beyond not being cancelled/no-show, so both are exposed as the same
    // controller action under two route names.
    public function addService(User $user, string $appointmentUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentUuid, $payload) {
            $appointment = $this->appointmentRepository->findByUuidForBranches($appointmentUuid, $branchIds);

            if (in_array($appointment->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true)) {
                return response()->json(['message' => 'Cannot add services to a cancelled or no-show appointment.'], 422);
            }

            if ($appointment->allServicesResolved()) {
                return response()->json(['message' => 'All services are completed — proceed to billing instead of adding more services.'], 422);
            }

            $nextSort = (int) ($appointment->services()->max('sort_order') ?? 0) + 1;
            $this->addServiceInternal($appointment, $appointment->spa_branch_id, $payload, null, $nextSort);
            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointmentUuid));
        });
    }

    // Only a service that hasn't started can be removed — once In Progress
    // or Completed it's part of the record (rule 17: preserve completed
    // service history). Status flip only, never deleted.
    public function removeService(User $user, string $appointmentServiceUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentServiceUuid) {
            $service = $this->appointmentServiceRepository->findByUuidForBranches($appointmentServiceUuid, $branchIds);

            if ($service->status !== 'Pending') {
                return response()->json(['message' => 'Only a service that has not started can be removed.'], 422);
            }

            $this->appointmentServiceRepository->cancel($service);

            $appointment = $service->appointment;
            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // Explodes the package into one AppointmentServiceItem per
    // package_services line (x quantity), tagged back to the purchase
    // record — see AppointmentPackage/AppointmentServiceItem docblocks.
    // This is what gives packages the same multi-therapist/room path as a
    // standalone service.
    public function addPackage(User $user, string $appointmentUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($business, $branchIds, $appointmentUuid, $payload) {
            $appointment = $this->appointmentRepository->findByUuidForBranches($appointmentUuid, $branchIds);

            if (in_array($appointment->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true)) {
                return response()->json(['message' => 'Cannot add packages to a cancelled or no-show appointment.'], 422);
            }

            if ($appointment->allServicesResolved()) {
                return response()->json(['message' => 'All services are completed — proceed to billing instead of adding more packages.'], 422);
            }

            $package = Package::where('uuid', $payload['package_uuid'])
                ->where('spa_business_id', $business->id)
                ->with('packageServiceItems.serviceVariant')
                ->firstOrFail();

            $branchPackage = BranchPackage::where('package_id', $package->id)
                ->where('spa_branch_id', $appointment->spa_branch_id)
                ->where('is_available', true)
                ->first();

            if (! $branchPackage) {
                return response()->json(['message' => 'This package is not available at this branch.'], 422);
            }

            $quantity = $payload['quantity'] ?? 1;
            $price = (float) ($branchPackage->custom_price ?? $package->default_price);

            $appointmentPackage = AppointmentPackage::create([
                'appointment_id' => $appointment->id,
                'package_id' => $package->id,
                'quantity' => $quantity,
                'unit_price' => $price,
                'subtotal' => $price * $quantity,
            ]);

            $nextSort = (int) ($appointment->services()->max('sort_order') ?? 0);

            foreach ($package->packageServiceItems as $index => $item) {
                $variant = $item->serviceVariant;

                // A package's component may not itself be individually sold
                // standalone at this branch — fall back to the variant's own
                // price so the package can still be exploded/billed
                // correctly; that's a separate concern from this branch's
                // own bookability.
                $branchService = BranchService::where('service_variant_id', $variant->id)
                    ->where('spa_branch_id', $appointment->spa_branch_id)
                    ->where('is_available', true)
                    ->first();

                $componentPrice = (float) ($branchService?->custom_price ?? $variant->price);
                $componentQty = ($item->quantity ?? 1) * $quantity;

                $this->appointmentServiceRepository->create([
                    'appointment_id' => $appointment->id,
                    'source_appointment_package_id' => $appointmentPackage->id,
                    'service_variant_id' => $variant->id,
                    'quantity' => $componentQty,
                    'sort_order' => $nextSort + $index + 1,
                    'unit_price' => $componentPrice,
                    'subtotal' => $componentPrice * $componentQty,
                    'points_earned' => ($variant->loyalty_points ?? 0) * $componentQty,
                ]);
            }

            $this->appointmentServiceRepository->recalculateAppointmentTotals($appointment);

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointmentUuid));
        });
    }

    // ── Therapist / room assignment ─────────────────────────────────────

    // Every active therapist at this service's branch (unfiltered by
    // qualification — the front-desk picker shows the whole roster, not
    // just who's qualified), each combined with today's attendance
    // check-in and their schedule for this exact appointment slot. Reuses
    // AttendanceStatusCalculator::resolve() (same status/shift-window logic
    // the Attendance pages already use) and
    // AppointmentAvailabilityService::staffMatchesSchedule() (the exact
    // check assignTherapistInternal() already gates on) rather than
    // reimplementing either. `pickable` is check-in only — a therapist
    // working outside their scheduled shift is still pickable, just
    // flagged 'checked_in_off_schedule', since attendance (not schedule)
    // is the real-world fact that matters for "can they actually do this
    // right now."
    public function therapistOptions(User $user, string $appointmentServiceUuid)
    {
        $branchIds = $this->branchIds($user);

        $service = AppointmentServiceItem::whereHas('appointment', fn ($q) => $q->whereIn('spa_branch_id', $branchIds))
            ->where('uuid', $appointmentServiceUuid)
            ->with('serviceVariant', 'appointment')
            ->firstOrFail();

        $appointment = $service->appointment;
        $date = $appointment->appointment_date->format('Y-m-d');
        $time = $appointment->appointment_time;
        $duration = $service->serviceVariant?->duration_minutes ?? 30;
        $dayOfWeek = Carbon::parse($date)->format('l');

        $therapists = Staff::where('spa_branch_id', $appointment->spa_branch_id)
            ->where('role', 'therapist')
            ->where('status', 'active')
            ->with('schedules')
            ->orderBy('first_name')
            ->get();

        $attendanceByStaffId = Attendance::whereIn('staff_id', $therapists->pluck('id'))
            ->where('attendance_date', $date)
            ->get()
            ->keyBy('staff_id');

        $branchSchedule = BranchSchedule::where('spa_branch_id', $appointment->spa_branch_id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        $options = $therapists->map(function (Staff $staff) use ($attendanceByStaffId, $date, $time, $duration, $branchSchedule) {
            $attendance = $attendanceByStaffId->get($staff->id);
            $resolved = $this->attendanceStatusCalculator->resolve($staff, $attendance, $date, $staff->schedules, $branchSchedule);
            $scheduleCheck = $this->availabilityService->staffMatchesSchedule($staff->id, $date, $time, $duration);

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

            return [
                'uuid' => $staff->uuid,
                'name' => trim("{$staff->first_name} {$staff->last_name}"),
                'state' => $state,
                // Checked in is the only gate — a real, in-person fact
                // always overrides a static schedule (day off included);
                // see assignTherapistInternal(), which skips the schedule
                // check entirely once checked in, matching this exactly.
                'pickable' => $checkedIn,
                'checked_in_at' => $attendance?->check_in_at?->format('H:i'),
                'is_day_off' => $resolved['is_day_off'],
                'scheduled_start' => $resolved['scheduled_start'],
                'scheduled_end' => $resolved['scheduled_end'],
            ];
        })->values();

        return response()->json(['data' => $options]);
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

            if (in_array($service->status, ['Cancelled', 'Completed'], true)) {
                return response()->json(['message' => 'Cannot assign a therapist to a cancelled or completed service.'], 422);
            }

            // Room-only reservation — a room can be locked in for a service
            // before a therapist is picked (assigned ahead of time, or right
            // when the client is up for service); staff can be claimed onto
            // the same row later via assignTherapistInternal().
            if (empty($payload['staff_uuid'])) {
                if (empty($payload['facility_uuid'])) {
                    return response()->json(['message' => 'Either a therapist or a room is required.'], 422);
                }

                $roomResult = $this->assignRoomOnlyInternal($service, $service->appointment, $payload['facility_uuid']);
                if (! $roomResult['ok']) {
                    return response()->json(['message' => $roomResult['reason']], 422);
                }

                return new AppointmentResource($this->appointmentRepository->findByUuid($service->appointment->uuid));
            }

            $staff = $this->resolveStaffForBranch($payload['staff_uuid'], $service->appointment->spa_branch_id);
            if (! $staff) {
                return response()->json(['message' => 'Therapist not found or inactive at this branch.'], 422);
            }

            $result = $this->assignTherapistInternal($service, $service->appointment, $staff);
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

            if ($assignment->assignment_status === 'In Progress') {
                return response()->json(['message' => 'Cannot remove a therapist currently performing the service.'], 422);
            }

            // Closes the dangling-row case: an Assigned-but-never-started
            // row left over on a service that was already completed via a
            // different assignment (assignment_status alone wouldn't catch
            // this — it's still just 'Assigned').
            if (in_array($assignment->appointmentService->status, ['Completed', 'Cancelled'], true)) {
                return response()->json(['message' => 'This service is already completed — its assignments can no longer be changed.'], 422);
            }

            $this->therapistAssignmentRepository->cancel($assignment);

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

            if (in_array($assignment->assignment_status, ['Completed', 'Cancelled'], true)) {
                return response()->json(['message' => 'Cannot assign a room to a completed or cancelled assignment.'], 422);
            }

            $service = $assignment->appointmentService;
            $appointment = $service->appointment;

            // Same dangling-row case as cancelAssignment() — this
            // assignment row is only 'Assigned', but its service already
            // finished via a different assignment.
            if (in_array($service->status, ['Completed', 'Cancelled'], true)) {
                return response()->json(['message' => 'This service is already completed — its assignments can no longer be changed.'], 422);
            }

            $result = $this->assignRoomInternal($assignment, $appointment, $service, $payload['facility_uuid']);
            if (! $result['ok']) {
                return response()->json(['message' => $result['reason']], 422);
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // ── Queue ────────────────────────────────────────────────────────────

    // Places an already-checked-in appointment on today's queue. Called from
    // checkIn() and from createAppointment()'s walk-in path — never exposed
    // directly, since every checked-in appointment is queued by the time
    // either of those returns. No-op if a queue row already exists so callers
    // don't need to guard against calling it twice.
    private function addToQueueInternal(Appointment $appointment): void
    {
        if ($appointment->queue) {
            return;
        }

        $date = $appointment->appointment_date->format('Y-m-d');

        $this->queueRepository->create([
            'appointment_id' => $appointment->id,
            'spa_branch_id' => $appointment->spa_branch_id,
            'appointment_date' => $date,
            'queue_number' => $this->queueRepository->nextQueueNumber($appointment->spa_branch_id, $date),
        ]);
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

            $result = $this->startServiceInternal($service);
            if (! $result['ok']) {
                return response()->json(['message' => $result['reason']], 422);
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($service->appointment->uuid));
        });
    }

    // Shared by startService() (explicit front-desk click) and
    // completeService()'s same-therapist auto-continue below — one place
    // for what "actually starting a service" means (guards + transition),
    // so the automatic path can't drift from the manual one.
    private function startServiceInternal(AppointmentServiceItem $service): array
    {
        if ($service->status !== 'Pending') {
            return ['ok' => false, 'reason' => 'This service has already started, completed, or been cancelled.'];
        }

        $activeAssignments = $service->therapistAssignments->where('assignment_status', 'Assigned');
        if ($activeAssignments->isEmpty() || $activeAssignments->whereNotNull('staff_id')->isEmpty()) {
            return ['ok' => false, 'reason' => 'Assign at least one therapist before starting this service.'];
        }

        foreach ($activeAssignments as $assignment) {
            // Room-only rows (staff_id null, rule 7) have no therapist to
            // check busy-ness for — only the facility check below applies.
            if ($assignment->staff_id) {
                $busy = $this->availabilityService->isStaffCurrentlyBusy($assignment->staff_id, $assignment->id);
                if (! $busy['ok']) {
                    return ['ok' => false, 'reason' => $busy['reason']];
                }
            }

            if ($assignment->facility_id) {
                $occupied = $this->availabilityService->isFacilityCurrentlyOccupied($assignment->facility_id, $assignment->id);
                if (! $occupied['ok']) {
                    return ['ok' => false, 'reason' => $occupied['reason']];
                }
            }
        }

        $now = now();
        foreach ($activeAssignments as $assignment) {
            $assignment->update(['assignment_status' => 'In Progress', 'started_at' => $now]);
        }
        $service->update(['status' => 'In Progress']);

        $appointment = $service->appointment;

        // First service on this appointment to actually start moves the
        // header from Checked In to In Service — a real stored status now,
        // replacing the old derived checked_in_in_service label. Stays In
        // Service (doesn't bounce back) if a later service on the same
        // appointment starts too.
        if ($appointment->canTransitionTo(Appointment::STATUS_IN_SERVICE)) {
            $appointment->update(['status' => Appointment::STATUS_IN_SERVICE]);
        }

        // served_at anchors the whole visit's "Estimated finish" (servedAt +
        // totalDurationMinutes, see QueueStatusPanel.vue/QueueView.vue) — set
        // it only the first time the ticket actually enters Serving. A later
        // service on the same visit starting (auto-continue or manual) must
        // not push it forward, or that estimate would silently inflate by
        // however long the earlier service(s) already took.
        if ($appointment->queue && $appointment->queue->queue_status !== 'Serving') {
            $this->queueRepository->updateStatus($appointment->queue, 'Serving', ['served_at' => $now]);
        }

        return ['ok' => true, 'reason' => null];
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

            if ($service->status !== 'In Progress') {
                return response()->json(['message' => 'Only a service currently in progress can be completed.'], 422);
            }

            $now = now();
            $completedStaffIds = $service->therapistAssignments
                ->where('assignment_status', 'In Progress')
                ->pluck('staff_id')
                ->filter()
                ->all();
            foreach ($service->therapistAssignments->where('assignment_status', 'In Progress') as $assignment) {
                $assignment->update(['assignment_status' => 'Completed', 'completed_at' => $now]);
            }
            $service->update(['status' => 'Completed']);

            $appointment = $service->appointment;

            // Same therapist continuing straight on to the client's next
            // service needs no separate "Start Service" click — they never
            // actually left. Only the very next Pending service (by sort
            // order) is considered; if it has a different therapist (or
            // none yet), it's left Pending and the normal manual
            // "Another service is ready" prompt (QueueStatusPanel.vue)
            // still covers it exactly as before.
            $nextService = $appointment->services()
                ->where('status', 'Pending')
                ->orderBy('sort_order')
                ->with('therapistAssignments')
                ->first();

            if ($nextService && $completedStaffIds) {
                $nextAssignment = $nextService->therapistAssignments
                    ->first(fn ($a) => $a->assignment_status === 'Assigned' && in_array($a->staff_id, $completedStaffIds, true));
                if ($nextAssignment) {
                    $this->startServiceInternal($nextService);
                }
            }

            $stillActive = $appointment->services()->whereIn('status', ['Pending', 'In Progress'])->exists();

            if (! $stillActive && $appointment->queue) {
                $this->queueRepository->updateStatus($appointment->queue, 'Completed', ['completed_at' => $now]);
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // ── Billing / payment ────────────────────────────────────────────────

    // Rejects unless every service is Completed or Cancelled (rule 12/14),
    // and rejects if a Billing row already exists for this appointment
    // (duplicate-billing guard, rule 14). Uses the appointment header's
    // total_amount directly — it's already kept current by
    // recalculateAppointmentTotals() on every add/remove, so it correctly
    // reflects the final actual availed services (originals + additions).
    public function proceedToBilling(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($business, $branchIds, $uuid) {
            $appointment = Appointment::whereIn('spa_branch_id', $branchIds)
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->with('services')
                ->firstOrFail();

            $unresolved = $appointment->services->whereNotIn('status', ['Completed', 'Cancelled'])->isNotEmpty();
            if ($unresolved) {
                return response()->json(['message' => 'All services must be completed or cancelled before billing.'], 422);
            }

            if ($this->billingRepository->findForAppointment($appointment->id)) {
                return response()->json(['message' => 'This appointment has already been billed.'], 422);
            }

            $amount = (float) $appointment->total_amount;
            if ($amount <= 0) {
                return response()->json(['message' => 'This appointment has no billable amount.'], 422);
            }

            $this->billingRepository->create([
                'appointment_id' => $appointment->id,
                'spa_business_id' => $business->id,
                'spa_branch_id' => $appointment->spa_branch_id,
                'billing_type' => 'Appointment',
                'billing_number' => $this->billingRepository->generateBillingNumber(),
                'amount' => $amount,
                'status' => 'Pending',
                'issued_at' => now(),
            ]);

            return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
        });
    }

    public function getBilling(User $user, string $billingUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // Full appointment context (client, branch, itemized services +
        // their therapist assignments) so the Billing & Payments detail
        // page can render Appointment Details / Bill Summary without a
        // second round trip to GET appointment/{uuid}.
        $billing = Billing::with([
            'payments',
            'appointment.client',
            'appointment.branch',
            'appointment.services.serviceVariant.service',
            'appointment.services.therapistAssignments.staff',
            'appointment.services.therapistAssignments.facility',
        ])
            ->where('uuid', $billingUuid)
            ->whereIn('spa_branch_id', $this->branchIds($user))
            ->firstOrFail();

        return new \App\Http\Resources\BillingResource($billing);
    }

    // Supports split/partial payments — the existing Payment model already
    // allows several rows per billing. The appointment only flips to
    // Completed once the paid total covers the billed amount (rule 13:
    // "appointment only fully completes after billing/payment").
    public function recordPayment(User $user, string $billingUuid, array $payload)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($business, $branchIds, $billingUuid, $payload) {
            $billing = Billing::where('uuid', $billingUuid)
                ->whereIn('spa_branch_id', $branchIds)
                ->lockForUpdate()
                ->firstOrFail();

            if ($billing->status === 'Paid') {
                return response()->json(['message' => 'This billing has already been fully paid.'], 422);
            }

            $payment = $this->paymentRepository->create([
                'billing_id' => $billing->id,
                'spa_business_id' => $business->id,
                'spa_branch_id' => $billing->spa_branch_id,
                'payment_method' => $payload['payment_method'],
                'reference_number' => $payload['reference_number'] ?? null,
                'amount' => $payload['amount'],
                'payment_status' => 'Paid',
                'paid_at' => now(),
                'remarks' => $payload['remarks'] ?? null,
            ]);

            $paidTotal = $this->paymentRepository->paidTotalForBilling($billing->id);

            if ($paidTotal >= (float) $billing->amount) {
                $billing->update(['status' => 'Paid', 'paid_at' => now()]);

                if ($billing->appointment_id) {
                    $paidAppointment = Appointment::find($billing->appointment_id);

                    if ($paidAppointment && $paidAppointment->canTransitionTo(Appointment::STATUS_COMPLETED)) {
                        $paidAppointment->update([
                            'status' => Appointment::STATUS_COMPLETED,
                            'completed_at' => now(),
                        ]);
                    }
                }
            }

            return new PaymentResource($payment);
        });
    }

    // ── Internal helpers ─────────────────────────────────────────────────

    private function resolveStaffForBranch(string $staffUuid, int $branchId): ?Staff
    {
        return Staff::where('uuid', $staffUuid)
            ->where('spa_branch_id', $branchId)
            ->where('status', 'active')
            ->first();
    }

    // Aborts the surrounding transaction (422) if the variant isn't
    // bookable at this branch at all — a hard data-integrity condition, not
    // a soft business-rule branch like the availability checks below.
    private function addServiceInternal(Appointment $appointment, int $branchId, array $item, ?int $sourcePackageId, int $sortOrder): AppointmentServiceItem
    {
        $variant = ServiceVariant::where('uuid', $item['service_variant_uuid'])->firstOrFail();

        $branchService = BranchService::where('service_variant_id', $variant->id)
            ->where('spa_branch_id', $branchId)
            ->where('is_available', true)
            ->first();

        if (! $branchService) {
            abort(422, 'This service is not available at the selected branch.');
        }

        $quantity = $item['quantity'] ?? 1;
        $price = (float) ($branchService->custom_price ?? $variant->price);

        // Removing a service is a status flip, not a delete (rule 17:
        // preserve history) — so re-adding the exact same variant right
        // after would otherwise pile up a second Cancelled+Pending pair for
        // it instead of just reactivating the one that's already there.
        // Package-exploded lines are excluded: reactivating one line from a
        // cancelled package item is a different question (the whole package
        // purchase, not just this line) and isn't what this covers.
        if ($sourcePackageId === null) {
            $existingCancelled = $appointment->services()
                ->where('service_variant_id', $variant->id)
                ->whereNull('source_appointment_package_id')
                ->where('status', 'Cancelled')
                ->first();

            if ($existingCancelled) {
                $existingCancelled->update([
                    'status' => 'Pending',
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'subtotal' => $price * $quantity,
                    'points_earned' => ($variant->loyalty_points ?? 0) * $quantity,
                    'notes' => $item['notes'] ?? null,
                    'sort_order' => $sortOrder,
                ]);

                return $existingCancelled;
            }
        }

        return $this->appointmentServiceRepository->create([
            'appointment_id' => $appointment->id,
            'source_appointment_package_id' => $sourcePackageId,
            'service_variant_id' => $variant->id,
            'quantity' => $quantity,
            'sort_order' => $sortOrder,
            'unit_price' => $price,
            'discount_amount' => $item['discount_amount'] ?? 0,
            'subtotal' => $price * $quantity,
            'points_earned' => ($variant->loyalty_points ?? 0) * $quantity,
            'notes' => $item['notes'] ?? null,
        ]);
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
    private function assignTherapistInternal(AppointmentServiceItem $service, Appointment $appointment, Staff $staff): array
    {
        if ($staff->role !== 'therapist') {
            return ['ok' => false, 'reason' => 'This staff member is not a therapist.', 'assignment' => null];
        }

        $duration = $service->serviceVariant?->duration_minutes ?? 30;
        $date = $appointment->appointment_date->format('Y-m-d');

        $checks = [
            $this->availabilityService->isStaffQualified($staff->id, $service->serviceVariant->service_id),
        ];

        $isCheckedIn = Attendance::where('staff_id', $staff->id)
            ->where('attendance_date', $date)
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

        // If a room was already reserved for this service ahead of a
        // therapist, claim that same row rather than creating a second one
        // — preserves the room instead of losing/duplicating it.
        $unclaimed = $this->therapistAssignmentRepository->findUnclaimedForService($service->id);
        $assignment = $unclaimed
            ? $this->therapistAssignmentRepository->claim($unclaimed, $staff->id)
            : $this->therapistAssignmentRepository->assign($service->id, $staff->id);

        return ['ok' => true, 'reason' => null, 'assignment' => $assignment];
    }

    // Unlike assignTherapistInternal() above, rooms ARE time-blocked: a
    // physical room can't serve two appointments at once, so both the
    // service-support check and isFacilityAvailable() are hard gates here,
    // not advisory (see AppointmentAvailabilityService's class doc comment).
    private function assignRoomInternal(TherapistAssignment $assignment, Appointment $appointment, AppointmentServiceItem $service, string $facilityUuid): array
    {
        $facility = Facility::where('uuid', $facilityUuid)->where('spa_branch_id', $appointment->spa_branch_id)->first();
        if (! $facility) {
            return ['ok' => false, 'reason' => 'Room not found at this branch.'];
        }

        $roomCheck = $this->checkRoomEligibility($facility, $appointment, $service, $assignment->id);
        if (! $roomCheck['ok']) {
            return $roomCheck;
        }

        $this->therapistAssignmentRepository->assignFacility($assignment, $facility->id);

        return ['ok' => true, 'reason' => null];
    }

    // Reserves a room for a service with no therapist assigned yet (rule:
    // room independent of staff). Same hard gates as assignRoomInternal().
    private function assignRoomOnlyInternal(AppointmentServiceItem $service, Appointment $appointment, string $facilityUuid): array
    {
        $facility = Facility::where('uuid', $facilityUuid)->where('spa_branch_id', $appointment->spa_branch_id)->first();
        if (! $facility) {
            return ['ok' => false, 'reason' => 'Room not found at this branch.'];
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
        $serviceId = $service->serviceVariant?->service_id;
        if ($serviceId && $facility->services()->exists() && ! $facility->services()->where('services.id', $serviceId)->exists()) {
            return ['ok' => false, 'reason' => 'This room does not support the selected service.'];
        }

        $duration = $service->serviceVariant?->duration_minutes ?? 30;
        $date = $appointment->appointment_date->format('Y-m-d');

        return $this->availabilityService->isFacilityAvailable($facility->id, $date, $appointment->appointment_time, $duration, $excludeAssignmentId, $appointment->id);
    }
}
