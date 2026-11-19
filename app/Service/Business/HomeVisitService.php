<?php

namespace App\Service\Business;

use App\Enums\AppointmentServiceStatus;
use App\Enums\AppointmentStatus;
use App\Enums\AssignmentStatus;
use App\Enums\BillingStatus;
use App\Enums\PaymentStatus;
use App\Enums\QueueStatus;
use App\Exceptions\ActionRefused;
use App\Http\Resources\AppointmentResource;
use App\Models\Addon;
use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Billing;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Repository\BillingRepository;
use App\Repository\Business\AppointmentRepository;
use App\Repository\Business\QueueRepository;
use App\Repository\PaymentRepository;
use App\Repository\SpaBusinessRepository;
use App\Service\Client\BookingPolicy;
use App\Support\Activity\History;
use App\Support\Visits\HomeVisitStart;
use App\Support\Visits\StaleVisit;
use App\Support\Platform\Suspension;
use Illuminate\Support\Facades\DB;

/**
 * A home visit: started by scanning the client's code at the door, or at the desk without it,
 * its services finished, billed and paid at the door (HomeVisitDoorService runs the door page).
 *
 * Split out of AppointmentService (which still hands every call on, for its older callers).
 */
class HomeVisitService
{
    public function __construct(
        private AppointmentRepository $appointmentRepository,
        private QueueRepository $queueRepository,
        private SpaBusinessRepository $spaBusinessRepository,
        private BillingRepository $billingRepository,
        private PaymentRepository $paymentRepository,
        private ClientNotifier $clientNotifier,
        private StaffNotifier $staffNotifier,
        private BillingService $billingService,
        private ServiceExecutionService $execution,
    ) {}

    // Starting a home visit from the staff member's scan of the client's QR
    // (App\Support\Visits\HomeVisitStart) — through $branch's own scanner link, so
    // only that branch's bookings are ever found. Does what the desk's Check
    // In and Start do, minus the queue number: nobody is waiting at the spa.
    // It starts the first service that can start and any that may run beside
    // it; the rest follow as they do at the desk. Every rule of
    // startServiceInternal() holds. A refusal (ActionRefused and the rest) rolls the whole thing back.
    public function startHomeVisitByCode(SpaBranch $branch, string $code): array
    {
        return DB::transaction(function () use ($branch, $code) {
            $appointment = Appointment::where('spa_branch_id', $branch->id)
                ->where('home_start_code', $code)
                ->lockForUpdate()
                ->first();
            throw_if(! $appointment, ActionRefused::class, "This code isn't for a booking at {$branch->branch_name}. Check the code, or use the scanner of the branch the client booked with.");

            return $this->startHomeVisit($appointment, $branch, null);
        });
    }

    // The front desk starting a home visit when the client's QR can't be
    // scanned (no app, a dead phone, no signal): exactly what the scan does —
    // checked in and started with no queue number, and none of the walk-in
    // questions (early, late, the queue's size) — and written on the booking
    // as done at the desk, by whom, rather than by scan.
    public function startHomeVisitAtDesk(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branchIds = $this->branchIds($user);

        return DB::transaction(function () use ($user, $branchIds, $uuid) {
            $found = $this->appointmentRepository->findByUuidForBranches($uuid, $branchIds, []);
            $appointment = Appointment::whereKey($found->id)->lockForUpdate()->firstOrFail();

            if (! $appointment->isHomeService()) {
                return response()->json(['message' => 'Only a home visit starts this way. Check the client in instead.'], 422);
            }
            if ($stale = StaleVisit::refusal($appointment)) {
                return $stale;
            }
            // A suspended branch winds down: starting a visit starts something new.
            if ($suspended = Suspension::refusal($appointment->branch)) {
                return $suspended;
            }

            $this->startHomeVisit($appointment, $appointment->branch, $user);

            return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
        });
    }

    // A home visit's start, by scan ($by null) or at the desk ($by): checked in
    // without a queue number, then the first service that can start and any
    // that may run beside it. Every rule of startServiceInternal() holds.
    // Called inside a transaction; a refusal (ActionRefused and the rest) rolls the whole thing back.
    private function startHomeVisit(Appointment $appointment, SpaBranch $branch, ?User $by): array
    {
        $problem = HomeVisitStart::problem($appointment->status, $appointment->appointment_date, BookingPolicy::now());
        throw_if($problem !== null, ActionRefused::class, (string) $problem);

        $pending = AppointmentServiceItem::where('appointment_id', $appointment->id)
            ->where('status', AppointmentServiceStatus::Pending->value)
            // An add-on starts with its main service.
            ->when(Addon::ready(), fn ($q) => $q->whereNull('parent_appointment_service_id'))
            ->with('therapistAssignments')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        throw_if($pending->isEmpty(), ActionRefused::class, 'There is nothing left to start on this visit.');

        // Arrived: what Check In does, without a queue number.
        if ($appointment->status === AppointmentStatus::Scheduled->value) {
            $appointment->update(['status' => AppointmentStatus::CheckedIn->value, 'check_in_at' => now()]);
        }

        $started = collect();
        $reason = null;
        foreach ($pending as $service) {
            $service->setRelation('appointment', $appointment);
            $result = $this->execution->startServiceInternal($service);
            if ($result['ok']) {
                $started->push($service);
            } else {
                $reason ??= $result['reason'];
            }
        }
        throw_if($started->isEmpty(), ActionRefused::class, $by ? ($reason ?? 'This visit can\'t be started yet.') : $this->scanRefusal($reason));

        // How it was started: the scan's time, or a note of who started it at the desk.
        if ($by) {
            $appointment->update(['remarks' => trim(($appointment->remarks ? $appointment->remarks.' · ' : '')."Home visit started without a scan by {$by->displayName()}")]);
        } else {
            $appointment->update(['home_scanned_at' => now()]);
        }

        $staff = TherapistAssignment::whereIn('appointment_service_id', $started->pluck('id'))
            ->where('assignment_status', AssignmentStatus::InProgress->value)
            ->whereNotNull('staff_id')
            ->with('staff')
            ->get()
            ->map(fn ($a) => trim("{$a->staff?->first_name} {$a->staff?->last_name}"))
            ->filter()
            ->unique()
            ->values();
        $services = $started->map(fn (AppointmentServiceItem $s) => $s->displayName())->filter()->values();
        $client = $appointment->client;
        $clientName = trim("{$client?->first_name} {$client?->last_name}") ?: 'A client';

        $appointment->setRelation('branch', $branch);
        $this->clientNotifier->appointment(
            $appointment,
            'Your home service has started',
            ($staff->isNotEmpty() ? $staff->join(', ', ' and ') : 'Your therapist').' started your '.($services->join(', ', ' and ') ?: 'service').'.',
        );
        $this->staffNotifier->appointment(
            $appointment,
            'Home visit started',
            $by
                ? "{$by->displayName()} started {$clientName}'s home service without a scan. Booking no. {$appointment->appointment_number}."
                : ($staff->isNotEmpty() ? $staff->join(', ', ' and ') : 'The staff')." started {$clientName}'s home service by scan. Booking no. {$appointment->appointment_number}.",
        );

        return [
            'client_first_name' => $client?->first_name,
            'booking_number' => $appointment->appointment_number,
            'address' => $appointment->home_address,
            'services' => $services->all(),
            // Services that couldn't start beside those: they follow.
            'still_to_start' => $pending->count() - $started->count(),
            'staff' => $staff->all(),
            'started_at' => now()->toIso8601String(),
        ];
    }

    // startServiceInternal()'s reasons are worded for the front desk; the
    // person holding the scanner is the staff member at the client's door.
    private function scanRefusal(?string $reason): string
    {
        if ($reason === null) {
            return 'This visit can\'t be started. Call the Front Officer.';
        }
        if (str_starts_with($reason, 'Assign at least one staff member')) {
            return 'No staff is assigned to this visit yet. Call the Front Officer.';
        }

        return str_replace(
            'Time them in on Attendance before starting this service.',
            'Ask a Front Officer to time them in first.',
            $reason,
        );
    }

    // ── A home visit at the client's door (HomeVisitDoorService) ─────────
    // Nobody is signed in there: the branch's scanner link and the visit's code are the proof,
    // the same as a scan. Each is called inside the door's transaction, the appointment locked.

    // The door's "Start service": what a scan has always done.
    public function startHomeVisitAtDoor(Appointment $appointment, SpaBranch $branch): array
    {
        return $this->startHomeVisit($appointment, $branch, null);
    }

    // The door's "Service done": what is running on the visit is completed as the desk's
    // Complete does (add-ons with their service, earnings fixed). Then whatever is left that can
    // start does, since everyone is already at the home; the rest wait for the desk.
    public function completeHomeVisitServices(Appointment $appointment): void
    {
        $lines = fn (string $status) => AppointmentServiceItem::where('appointment_id', $appointment->id)
            ->where('status', $status)
            ->when(Addon::ready(), fn ($q) => $q->whereNull('parent_appointment_service_id'))
            ->with('therapistAssignments')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $running = $lines(AssignmentStatus::InProgress->value);
        throw_if($running->isEmpty(), ActionRefused::class, 'Nothing is running on this visit to finish.');

        $now = now();
        foreach ($running as $line) {
            $line->setRelation('appointment', $appointment);
            $this->execution->completeLine($line, $now);
        }
        foreach ($lines(AppointmentServiceStatus::Pending->value) as $next) {
            $next->setRelation('appointment', $appointment);
            $this->execution->startServiceInternal($next);
        }

        if ($appointment->queue && ! $appointment->services()->whereIn('status', [AppointmentServiceStatus::Pending->value, AppointmentServiceStatus::InProgress->value])->exists()) {
            $this->queueRepository->updateStatus($appointment->queue, QueueStatus::Completed->value, ['completed_at' => $now]);
        }
    }

    // The visit's bill, made the first time the door asks once every service is done (the desk's
    // Proceed to Billing, with the voucher booked with). A visit with nothing to pay (a package
    // another client paid for) is settled at once and completes.
    public function billHomeVisit(Appointment $appointment): Billing
    {
        if ($billing = $this->billingRepository->findForAppointment($appointment->id)) {
            return $billing;
        }
        throw_if(
            $appointment->services()->whereNotIn('status', [AppointmentServiceStatus::Completed->value, AppointmentServiceStatus::Cancelled->value])->exists(),
            ActionRefused::class,
            'Finish the services first (Service done), then check out.',
        );

        $amount = max(0, round((float) $appointment->fresh()->total_amount, 2));
        $billing = $this->billingRepository->create([
            'appointment_id' => $appointment->id,
            'spa_business_id' => $appointment->branch->spa_business_id,
            'spa_branch_id' => $appointment->spa_branch_id,
            'billing_type' => 'Appointment',
            'billing_number' => $this->billingRepository->generateBillingNumber(),
            'subtotal' => $amount,
            'amount' => $amount,
            'status' => BillingStatus::Pending->value,
            'issued_at' => now(),
        ]);
        $this->billingService->applyReservedVoucher($billing);
        $this->billingService->settle($billing->fresh());

        return $this->billingRepository->findForAppointment($appointment->id);
    }

    // The payment the staff took at the door. Every check of the desk's (a method switched on
    // in Settings → Payments, a reference for an e-wallet, no more than the balance, cash
    // covering it), recorded as taken at the client's home by $by, with no account behind it.
    // Paid in full: the bill is Paid and the visit completes (BillingService::settle).
    public function payHomeVisit(Appointment $appointment, Billing $billing, array $payload, string $by): array
    {
        $billing = Billing::whereKey($billing->id)->lockForUpdate()->firstOrFail();
        $business = SpaBusiness::findOrFail($billing->spa_business_id);
        if ($rejection = $this->billingService->paymentRejection($billing, $business, $payload)) {
            throw new ActionRefused((string) ($rejection->getData(true)['message'] ?? 'This payment can\'t be taken.'));
        }

        $amount = round((float) $payload['amount'], 2);
        $tendered = isset($payload['amount_tendered']) ? round((float) $payload['amount_tendered'], 2) : null;
        // No received_by at all (not even null): the Payment's own hook would name nobody over it.
        $payment = $this->paymentRepository->create([
            'billing_id' => $billing->id,
            'spa_business_id' => $business->id,
            'spa_branch_id' => $billing->spa_branch_id,
            'payment_method' => $payload['payment_method'],
            'reference_number' => $payload['reference_number'] ?? null,
            'amount' => $amount,
            'amount_tendered' => $tendered,
            'change_given' => $tendered !== null ? round($tendered - $amount, 2) : null,
            'payment_status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
            'remarks' => "Received at the client's home by {$by}",
        ] + (History::ready() ? ['received_by_name' => "{$by} at the client's home"] : []));

        $money = '₱'.number_format($amount, 2);
        $this->clientNotifier->appointment(
            $appointment,
            'Payment received',
            "{$appointment->branch?->branch_name} received your payment of {$money} for booking {$appointment->appointment_number}. Thank you!",
            'Payment',
        );
        // The desk reconciles it: the cash comes back with the staff.
        $this->staffNotifier->appointment(
            $appointment,
            'Payment received at a home visit',
            "{$by} received {$money} ({$payload['payment_method']}) at the client's home for booking {$appointment->appointment_number}.",
            null,
            'Payment',
        );

        $this->billingService->settle($billing);
        $billing = $billing->fresh();

        return [
            'method' => $payment->payment_method,
            'amount' => $amount,
            'amount_tendered' => $tendered,
            'change' => $payment->change_given !== null ? (float) $payment->change_given : null,
            'reference_number' => $payment->reference_number,
            'billing_number' => $billing->billing_number,
            'billing_status' => $billing->status,
            'balance' => $this->billingService->balance($billing),
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ];
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
