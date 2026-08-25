<?php

namespace App\Service\Business;

use App\Http\Resources\AppointmentResource;
use App\Http\Resources\PaymentResource;
use App\Models\Appointment;
use App\Models\AppointmentPackage;
use App\Models\AppointmentServiceItem;
use App\Models\Billing;
use App\Models\BranchPackage;
use App\Models\BranchService;
use App\Models\Facility;
use App\Models\Package;
use App\Models\ServiceVariant;
use App\Models\Staff;
use App\Models\TherapistAssignment;
use App\Models\User;
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

            $client = ! empty($payload['client_uuid'])
                ? $this->clientRepository->findByUuidForBusiness($payload['client_uuid'], $business->id)
                : $this->clientService->findOrCreate($business->id, $payload['client'] ?? []);

            $appointment = $this->appointmentRepository->create([
                'spa_branch_id' => $branch->id,
                'client_id' => $client->id,
                'appointment_number' => $this->appointmentRepository->generateAppointmentNumber(),
                'appointment_date' => $payload['appointment_date'],
                'appointment_time' => $payload['appointment_time'],
                'appointment_type' => $payload['appointment_type'] ?? 'Reservation',
                'source' => $payload['source'] ?? 'Front Desk',
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

            $resource = new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));

            return $warnings ? $resource->additional(['warnings' => $warnings]) : $resource;
        });
    }

    // ── Status transitions ──────────────────────────────────────────────

    public function confirmAppointment(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));

        if ($appointment->status !== 'Pending') {
            return response()->json(['message' => 'Only a pending appointment can be confirmed.'], 422);
        }

        $appointment->update(['status' => 'Confirmed']);

        return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
    }

    // Check-in is distinct from starting service — it only flips the header
    // status and records check_in_at. Does NOT create a queue row (rule 8:
    // joining the queue vs. keeping the agreed time is a separate, explicit
    // decision made afterward via addToQueue()).
    public function checkIn(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));

        if (! in_array($appointment->status, ['Pending', 'Confirmed'], true)) {
            return response()->json(['message' => 'Only a pending or confirmed appointment can be checked in.'], 422);
        }

        $appointment->update(['status' => 'Checked In', 'check_in_at' => now()]);

        return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
    }

    public function markNoShow(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $appointment = $this->appointmentRepository->findByUuidForBranches($uuid, $this->branchIds($user));

        if (! in_array($appointment->status, ['Pending', 'Confirmed'], true)) {
            return response()->json(['message' => 'Only an appointment that has not been checked in can be marked as no-show.'], 422);
        }

        $appointment->update(['status' => 'No Show']);

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

            $blocked = $appointment->services()->whereIn('status', ['In Progress', 'Completed'])->exists();
            if ($blocked) {
                return response()->json(['message' => 'Cannot cancel an appointment with a service already in progress or completed.'], 422);
            }

            $serviceIds = $appointment->services()->pluck('id');

            $appointment->services()->where('status', 'Pending')->update(['status' => 'Cancelled']);

            TherapistAssignment::whereIn('appointment_service_id', $serviceIds)
                ->where('assignment_status', 'Assigned')
                ->update(['assignment_status' => 'Cancelled']);

            if ($appointment->queue) {
                $this->queueRepository->updateStatus($appointment->queue, 'Cancelled');
            }

            $appointment->update([
                'status' => 'Cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
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

            if (in_array($appointment->status, ['Cancelled', 'No Show'], true)) {
                return response()->json(['message' => 'Cannot add services to a cancelled or no-show appointment.'], 422);
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

            if (in_array($appointment->status, ['Cancelled', 'No Show'], true)) {
                return response()->json(['message' => 'Cannot add packages to a cancelled or no-show appointment.'], 422);
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

            $result = $this->assignRoomInternal($assignment, $appointment, $service, $payload['facility_uuid']);
            if (! $result['ok']) {
                return response()->json(['message' => $result['reason']], 422);
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
    }

    // ── Queue ────────────────────────────────────────────────────────────

    // A separate, explicit step from check-in (rule 8) — the front desk
    // decides to place the client in the queue rather than this happening
    // automatically.
    public function addToQueue(User $user, string $appointmentUuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($branchIds, $appointmentUuid) {
            $appointment = $this->appointmentRepository->findByUuidForBranches($appointmentUuid, $branchIds);

            if ($appointment->status !== 'Checked In') {
                return response()->json(['message' => 'Only a checked-in appointment can join the queue.'], 422);
            }

            if ($appointment->queue) {
                return response()->json(['message' => 'This appointment is already in the queue.'], 422);
            }

            $date = $appointment->appointment_date->format('Y-m-d');

            $this->queueRepository->create([
                'appointment_id' => $appointment->id,
                'spa_branch_id' => $appointment->spa_branch_id,
                'appointment_date' => $date,
                'queue_number' => $this->queueRepository->nextQueueNumber($appointment->spa_branch_id, $date),
            ]);

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointmentUuid));
        });
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

            if ($service->status !== 'Pending') {
                return response()->json(['message' => 'This service has already started, completed, or been cancelled.'], 422);
            }

            $activeAssignments = $service->therapistAssignments->where('assignment_status', 'Assigned');
            if ($activeAssignments->isEmpty()) {
                return response()->json(['message' => 'Assign at least one therapist before starting this service.'], 422);
            }

            foreach ($activeAssignments as $assignment) {
                $busy = $this->availabilityService->isStaffCurrentlyBusy($assignment->staff_id, $assignment->id);
                if (! $busy['ok']) {
                    return response()->json(['message' => $busy['reason']], 422);
                }

                if ($assignment->facility_id) {
                    $occupied = $this->availabilityService->isFacilityCurrentlyOccupied($assignment->facility_id, $assignment->id);
                    if (! $occupied['ok']) {
                        return response()->json(['message' => $occupied['reason']], 422);
                    }
                }
            }

            $now = now();
            foreach ($activeAssignments as $assignment) {
                $assignment->update(['assignment_status' => 'In Progress', 'started_at' => $now]);
            }
            $service->update(['status' => 'In Progress']);

            $appointment = $service->appointment;
            if ($appointment->queue) {
                $this->queueRepository->updateStatus($appointment->queue, 'Serving', ['served_at' => $now]);
            }

            return new AppointmentResource($this->appointmentRepository->findByUuid($appointment->uuid));
        });
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
            foreach ($service->therapistAssignments->where('assignment_status', 'In Progress') as $assignment) {
                $assignment->update(['assignment_status' => 'Completed', 'completed_at' => $now]);
            }
            $service->update(['status' => 'Completed']);

            $appointment = $service->appointment;
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

        $billing = Billing::with('payments', 'appointment.client')
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
                    Appointment::where('id', $billing->appointment_id)->update([
                        'status' => 'Completed',
                        'completed_at' => now(),
                    ]);
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

    // Qualification -> schedule -> availability, in that order, so the
    // first failing check gives the most useful reason. Never throws — the
    // caller decides whether a failure is fatal (explicit assignTherapist
    // action) or just a warning (requested therapist at creation time).
    private function assignTherapistInternal(AppointmentServiceItem $service, Appointment $appointment, Staff $staff): array
    {
        if ($staff->role !== 'therapist') {
            return ['ok' => false, 'reason' => 'This staff member is not a therapist.', 'assignment' => null];
        }

        $duration = $service->serviceVariant?->duration_minutes ?? 30;
        $date = $appointment->appointment_date->format('Y-m-d');

        $checks = [
            $this->availabilityService->isStaffQualified($staff->id, $service->serviceVariant->service_id),
            $this->availabilityService->staffMatchesSchedule($staff->id, $date, $appointment->appointment_time, $duration),
            $this->availabilityService->isStaffAvailable($staff->id, $date, $appointment->appointment_time, $duration),
        ];

        foreach ($checks as $check) {
            if (! $check['ok']) {
                return $check + ['assignment' => null];
            }
        }

        $assignment = $this->therapistAssignmentRepository->assign($service->id, $staff->id);

        return ['ok' => true, 'reason' => null, 'assignment' => $assignment];
    }

    private function assignRoomInternal(TherapistAssignment $assignment, Appointment $appointment, AppointmentServiceItem $service, string $facilityUuid): array
    {
        $facility = Facility::where('uuid', $facilityUuid)->where('spa_branch_id', $appointment->spa_branch_id)->first();
        if (! $facility) {
            return ['ok' => false, 'reason' => 'Room not found at this branch.'];
        }

        $duration = $service->serviceVariant?->duration_minutes ?? 30;
        $date = $appointment->appointment_date->format('Y-m-d');

        $available = $this->availabilityService->isFacilityAvailable($facility->id, $date, $appointment->appointment_time, $duration, $assignment->id);
        if (! $available['ok']) {
            return $available;
        }

        $this->therapistAssignmentRepository->assignFacility($assignment, $facility->id);

        return ['ok' => true, 'reason' => null];
    }
}
