<?php

namespace App\Service\Business;

use App\Enums\AppointmentServiceStatus;
use App\Enums\BillingStatus;
use App\Enums\PaymentStatus;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\PaymentResource;
use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Billing;
use App\Models\User;
use App\Repository\BillingRepository;
use App\Repository\Business\AppointmentRepository;
use App\Repository\PaymentRepository;
use App\Repository\SpaBusinessRepository;
use Illuminate\Support\Facades\DB;

/**
 * From services done to paid: billing a visit, and taking a payment on its bill.
 *
 * Split out of AppointmentService (which still hands every call on, for its older callers).
 */
class VisitBillingService
{
    public function __construct(
        private AppointmentRepository $appointmentRepository,
        private SpaBusinessRepository $spaBusinessRepository,
        private BillingRepository $billingRepository,
        private PaymentRepository $paymentRepository,
        private ClientNotifier $clientNotifier,
        private StaffNotifier $staffNotifier,
        private BillingService $billingService,
        private ServiceExecutionService $execution,
    ) {}

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

            $unresolved = $appointment->services->whereNotIn('status', [AppointmentServiceStatus::Completed->value, AppointmentServiceStatus::Cancelled->value])->isNotEmpty();
            if ($unresolved) {
                return response()->json(['message' => 'All services must be completed or cancelled before billing.'], 422);
            }

            if ($this->billingRepository->findForAppointment($appointment->id)) {
                return response()->json(['message' => 'This appointment has already been billed.'], 422);
            }

            $amount = (float) $appointment->total_amount;
            // Covered by another client's shared package: a ₱0 bill, settled at once, and the
            // visit completes — the client is not charged (SharedPackages::closesFree).
            if ($this->execution->closeCoveredVisit($appointment)) {
                return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
            }
            if ($amount <= 0) {
                return response()->json(['message' => 'This appointment has no billable amount.'], 422);
            }

            $billing = $this->billingRepository->create([
                'appointment_id' => $appointment->id,
                'spa_business_id' => $business->id,
                'spa_branch_id' => $appointment->spa_branch_id,
                'billing_type' => 'Appointment',
                'billing_number' => $this->billingRepository->generateBillingNumber(),
                'subtotal' => $amount,
                'amount' => $amount,
                'status' => BillingStatus::Pending->value,
                'issued_at' => now(),
            ]);

            // The voucher the client booked with goes on the bill.
            $this->billingService->applyReservedVoucher($billing);

            return new AppointmentResource($this->appointmentRepository->findByUuid($uuid));
        });
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

        return DB::transaction(function () use ($business, $branchIds, $billingUuid, $payload, $user) {
            $billing = Billing::where('uuid', $billingUuid)
                ->whereIn('spa_branch_id', $branchIds)
                ->lockForUpdate()
                ->firstOrFail();

            // Enabled method (Settings → Payments), reference for non-cash,
            // no overpayment, bill/appointment still open — BillingService.
            if ($rejection = $this->billingService->paymentRejection($billing, $business, $payload)) {
                return $rejection;
            }

            $tendered = isset($payload['amount_tendered']) ? round((float) $payload['amount_tendered'], 2) : null;
            $payment = $this->paymentRepository->create([
                'billing_id' => $billing->id,
                'spa_business_id' => $business->id,
                'spa_branch_id' => $billing->spa_branch_id,
                'payment_method' => $payload['payment_method'],
                'reference_number' => $payload['reference_number'] ?? null,
                'amount' => $payload['amount'],
                'amount_tendered' => $tendered,
                'change_given' => $tendered !== null ? round($tendered - (float) $payload['amount'], 2) : null,
                'payment_status' => PaymentStatus::Paid->value,
                'paid_at' => now(),
                'received_by' => $user->id,
                'remarks' => $payload['remarks'] ?? null,
            ]);

            $paidFor = $billing->appointment_id ? Appointment::find($billing->appointment_id) : null;
            if ($paidFor) {
                $amount = '₱'.number_format((float) $payload['amount'], 2);
                $this->clientNotifier->appointment(
                    $paidFor,
                    'Payment received',
                    "{$paidFor->branch?->branch_name} received your payment of {$amount} for booking {$paidFor->appointment_number}. Thank you!",
                    'Payment',
                );
                // Front desk included — they reconcile the cash drawer — but
                // not whoever just recorded it.
                $this->staffNotifier->appointment(
                    $paidFor,
                    'Payment received',
                    "{$amount} received for booking {$paidFor->appointment_number}.",
                    null,
                    'Payment',
                    exceptUserId: $user->id,
                );
            }

            // Paid in full → bill Paid, appointment Completed.
            $this->billingService->settle($billing);

            // The receipt needs the bill's remaining balance and status too.
            $payment->load('receiver');

            return (new PaymentResource($payment))->additional([
                'billing' => [
                    'uuid' => $billing->uuid,
                    'billing_number' => $billing->billing_number,
                    'amount' => (float) $billing->amount,
                    'status' => $billing->fresh()->status,
                    'balance' => $this->billingService->balance($billing),
                ],
            ]);
        });
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
