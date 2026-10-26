<?php

namespace App\Service\Business;

use App\Http\Resources\BillingResource;
use App\Http\Resources\PaymentResource;
use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Models\Billing;
use App\Models\ClientVoucher;
use App\Models\CustomerProgram;
use App\Models\Payment;
use App\Models\Review;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\PaymentRepository;
use App\Repository\SpaBusinessRepository;
use App\Support\FinishedVisit;
use App\Support\PackageShare;
use App\Support\ProgramChannel;
use App\Support\ServiceRefunds;
use App\Support\VoucherTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// The money side of an appointment bill, beyond creating it and taking a
// payment (those stay in AppointmentService::proceedToBilling/recordPayment,
// which call into the rules here):
//   - which payment methods the front desk may use — the owner's
//     Settings → Payments, enforced here rather than trusted from the UI;
//   - discounts (billing_update), voiding a payment entered by mistake and
//     refunding money handed back (payment_refund);
//   - settle(): the single place a bill becomes Paid (and its appointment
//     Completed), shared by payments and discounts.
class BillingService
{
    // Settings → Payments switch => the payment_method values it unlocks.
    public const METHODS_BY_SETTING = [
        'accept_cash' => ['Cash'],
        'accept_gcash' => ['GCash'],
        'accept_paymaya' => ['Maya'],
        'accept_card' => ['Credit Card', 'Debit Card'],
        'accept_bank_transfer' => ['Bank Transfer'],
    ];

    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private PaymentRepository $paymentRepository,
        private AuditLogRepository $auditLogRepository,
        private ClientNotifier $clientNotifier,
        private StaffNotifier $staffNotifier,
        private ProgramRewardService $rewards,
    ) {
    }

    // ── Payment methods ──────────────────────────────────────────────────

    /** @return string[] payment_method values the owner has switched on. */
    public function enabledMethods(?SpaBusiness $business): array
    {
        $business?->loadMissing('settings');
        $settings = $business?->settings?->section('payments') ?? SpaBusinessSetting::DEFAULTS['payments'];

        $methods = [];
        foreach (self::METHODS_BY_SETTING as $key => $values) {
            if ($settings[$key] ?? false) {
                array_push($methods, ...$values);
            }
        }

        return $methods;
    }

    // Anything but cash leaves a trail (GCash/Maya ref no., card approval
    // code, bank reference) the front desk must record.
    public function requiresReference(string $method): bool
    {
        return $method !== 'Cash';
    }

    public function paymentOptions(User $user)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        $settings = $business->settings?->section('payments') ?? SpaBusinessSetting::DEFAULTS['payments'];

        return [
            'data' => array_map(fn (string $method) => [
                'method' => $method,
                'requires_reference' => $this->requiresReference($method),
            ], $this->enabledMethods($business)),
            'gcash_number' => $settings['gcash_number'] ?? null,
            'paymaya_number' => $settings['paymaya_number'] ?? null,
        ];
    }

    // Why a payment can't be taken, or null when it can. Called inside
    // recordPayment's transaction with the billing row locked.
    public function paymentRejection(Billing $billing, SpaBusiness $business, array $payload): ?JsonResponse
    {
        if (in_array($billing->status, ['Paid', 'Cancelled', 'Refunded'], true)) {
            return $this->reject($billing->status === 'Paid' ? 'This bill has already been fully paid.' : "This bill is {$billing->status} and can't take payments.");
        }

        $appointment = $billing->appointment_id ? Appointment::find($billing->appointment_id) : null;
        if ($appointment && in_array($appointment->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true)) {
            return $this->reject('This appointment was cancelled — it can\'t take payments.');
        }

        $method = $payload['payment_method'];
        if (! in_array($method, $this->enabledMethods($business), true)) {
            return $this->reject("{$method} isn't accepted — turn it on in Settings → Payments first.");
        }

        if ($this->requiresReference($method) && blank($payload['reference_number'] ?? null)) {
            return $this->reject("Enter the {$method} reference number.");
        }

        if (\App\Models\BillingAdjustment::ready() && \App\Models\BillingAdjustment::where('billing_id', $billing->id)
            ->where('status', \App\Models\BillingAdjustment::PENDING)->where('kind', \App\Support\BillAdjustment::CREDIT)->exists()) {
            return $this->reject('A refund on this bill is waiting for approval. Take the payment once it is answered, or withdraw it.');
        }

        $balance = $this->balance($billing);
        $amount = round((float) $payload['amount'], 2);
        if ($amount > $balance + 0.004) {
            return $this->reject('That\'s more than the remaining balance of ₱' . number_format($balance, 2) . '.');
        }

        if (isset($payload['amount_tendered']) && $payload['amount_tendered'] !== null) {
            if ($method !== 'Cash') {
                return $this->reject('Amount received only applies to cash payments.');
            }
            if ((float) $payload['amount_tendered'] + 0.004 < $amount) {
                return $this->reject('The cash received is less than the amount being paid.');
            }
        }

        return null;
    }

    public function balance(Billing $billing): float
    {
        return max(0, round((float) $billing->amount - $this->paymentRepository->paidTotalForBilling($billing->id), 2));
    }

    // Marks the bill Paid once payments cover it, completing its appointment
    // (rule 13: an appointment only fully completes after payment). Safe to
    // call any time — does nothing while a balance remains.
    public function settle(Billing $billing): void
    {
        if ($billing->status === 'Paid' || $this->balance($billing) > 0) {
            return;
        }

        $billing->update(['status' => 'Paid', 'paid_at' => now()]);

        // Loyalty points and earned vouchers (customer programs).
        $this->rewards->onBillPaid($billing);

        $appointment = $billing->appointment_id ? Appointment::find($billing->appointment_id) : null;
        if ($appointment && $appointment->canTransitionTo(Appointment::STATUS_COMPLETED)) {
            $appointment->update(['status' => Appointment::STATUS_COMPLETED, 'completed_at' => now()]);
            // A spa is rated once: a client who already has is only thanked.
            $rated = $appointment->client?->user_id && Review::ratedBranch($appointment->client_id, $appointment->spa_branch_id);
            $this->clientNotifier->appointment(
                $appointment,
                'Thank you for visiting',
                "We hope you enjoyed your visit to {$appointment->branch?->branch_name}."
                    . ($rated ? ' See you again soon!' : ' Tap to rate your experience.'),
            );
        }
    }

    // ── Discounts ─────────────────────────────────────────────────────────

    // type 'amount' (₱) or 'percent' of the pre-discount subtotal; value 0
    // removes the discount. Can't go below what has already been paid.
    //
    // Or, instead of typing it in, the front desk picks a customer program:
    // a discount program ($programUuid) or one of the client's vouchers
    // ($voucherUuid) — the amount and reason then come from the program, and
    // the voucher is marked used (and handed back if the discount changes).
    public function applyDiscount(User $user, string $billingUuid, string $type, float $value, ?string $reason, ?Request $request = null, ?string $programUuid = null, ?string $voucherUuid = null, bool $programOnly = false)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        return DB::transaction(function () use ($user, $billingUuid, $type, $value, $reason, $request, $programUuid, $voucherUuid, $programOnly) {
            $billing = $this->lockedBilling($user, $billingUuid);

            if (in_array($billing->status, ['Paid', 'Cancelled', 'Refunded'], true)) {
                return $this->reject("A {$billing->status} bill can't be discounted.");
            }

            $subtotal = (float) ($billing->subtotal ?? $billing->amount);

            // Program-only callers (the rewards route — front desk without
            // billing_update) may swap or remove a program/voucher discount,
            // never a discount someone typed in by hand.
            $manual = (float) $billing->discount_amount > 0 && ! $billing->discount_program_id && ! $billing->client_voucher_id;
            if ($programOnly && $manual) {
                return $this->reject('This bill already has a manual discount — ask someone who can give discounts to change it.');
            }

            $program = null;
            $voucher = null;
            if ($voucherUuid || $programUuid) {
                $picked = $voucherUuid ? $this->voucherForBill($billing, $voucherUuid) : $this->discountProgramForBill($billing, $programUuid);
                if (is_string($picked)) {
                    return $this->reject($picked);
                }
                [$program, $voucher] = $picked;
                $derived = $this->programDiscount($billing, $program);
                if (is_string($derived)) {
                    return $this->reject($derived);
                }
                [$type, $value] = $derived;
                $reason = ($voucher ? 'Voucher: ' : 'Discount: ') . $program->name;
            }
            $discount = $type === 'percent' ? round($subtotal * min($value, 100) / 100, 2) : round(min($value, $subtotal), 2);
            $paid = $this->paymentRepository->paidTotalForBilling($billing->id);
            // Refunded before payment stays off, whatever the discount.
            $credit = (float) ($billing->credit_amount ?? 0);

            if ($subtotal - $discount - $credit + 0.004 < $paid) {
                return $this->reject('₱' . number_format($paid, 2) . ' has already been paid — the discount can\'t bring the total below that.');
            }
            if ($value > 0 && blank($reason)) {
                return $this->reject('Give a reason for the discount.');
            }

            // A voucher that was on this bill goes back to the client's
            // wallet unless it's the one being applied again.
            if ($billing->client_voucher_id && $billing->client_voucher_id !== $voucher?->id) {
                ClientVoucher::whereKey($billing->client_voucher_id)->where('status', 'used')
                    ->update(['status' => 'available', 'used_at' => null, 'used_billing_id' => null, 'used_by' => null]);
            }

            $old = $billing->only(['subtotal', 'discount_type', 'discount_value', 'discount_amount', 'discount_reason', 'amount']);
            $billing->update($value > 0 ? $this->discountFields($subtotal, $type, $value, $discount, $reason, $user->id, $program, $voucher, $credit) : [
                'subtotal' => $subtotal,
                'discount_type' => null,
                'discount_value' => null,
                'discount_amount' => 0,
                'discount_reason' => null,
                'discounted_by' => null,
                'discount_program_id' => null,
                'client_voucher_id' => null,
                'amount' => round(max(0, $subtotal - $credit), 2),
            ]);

            if ($voucher && $value > 0) {
                $voucher->update(['status' => 'used', 'used_at' => now(), 'used_billing_id' => $billing->id, 'used_by' => $user->id]);
            }

            $this->auditLogRepository->record($user->id, 'billings', $billing->id, 'Update', $old, $billing->only(array_keys($old)), $request);
            $this->settle($billing->fresh());

            return new BillingResource($this->detailed($billing->uuid));
        });
    }

    // The bill's columns for a discount that is on it. $credit: what was
    // refunded before payment, which stays off the total too.
    private function discountFields(float $subtotal, string $type, float $value, float $discount, string $reason, ?int $byUserId, ?CustomerProgram $program, ?ClientVoucher $voucher, float $credit = 0.0): array
    {
        return [
            'subtotal' => $subtotal,
            'discount_type' => $type,
            'discount_value' => $value,
            'discount_amount' => $discount,
            'discount_reason' => $reason,
            'discounted_by' => $byUserId,
            'discount_program_id' => $program?->id,
            'client_voucher_id' => $voucher?->id,
            'amount' => round(max(0, $subtotal - $discount - $credit), 2),
        ];
    }

    // The voucher a client booked with in the app (appointments.client_voucher_id)
    // goes on the bill when it is made: worked out from the bill's own services
    // like one the front desk applies, but by the system rather than a person.
    // When it no longer fits (a service was cancelled…) or can't be used any
    // more, the bill stays as it is and the booking lets go of it.
    public function applyReservedVoucher(Billing $billing): void
    {
        $appointment = Appointment::holdsVouchers() && $billing->appointment_id ? Appointment::find($billing->appointment_id) : null;
        if (! $appointment?->client_voucher_id) {
            return;
        }

        $voucher = ClientVoucher::with('program')->find($appointment->client_voucher_id);
        $program = $voucher?->dealProgram();
        $derived = $voucher && $voucher->isUsable() && $program ? $this->programDiscount($billing, $program) : null;
        if (! is_array($derived)) {
            $appointment->update(['client_voucher_id' => null]);
            $this->tellVoucherNotApplied($appointment, $voucher, $program, is_string($derived) ? $derived : null);

            return;
        }

        [$type, $value] = $derived;
        $subtotal = (float) ($billing->subtotal ?? $billing->amount);
        $discount = $type === 'percent' ? round($subtotal * min($value, 100) / 100, 2) : round(min($value, $subtotal), 2);
        if ($discount <= 0) {
            $appointment->update(['client_voucher_id' => null]);
            $this->tellVoucherNotApplied($appointment, $voucher, $program, null);

            return;
        }

        $billing->update($this->discountFields($subtotal, $type, $value, $discount, 'Voucher: ' . $program->name, null, $program, $voucher, (float) ($billing->credit_amount ?? 0)));
        $voucher->update(['status' => 'used', 'used_at' => now(), 'used_billing_id' => $billing->id, 'used_by' => null]);
        $this->settle($billing->fresh());
    }

    // The client booked with a voucher and it could not go on the bill: they pay the
    // full price, so they are told, and why. A voucher that is still good stays in
    // their wallet.
    private function tellVoucherNotApplied(Appointment $appointment, ?ClientVoucher $voucher, $program, ?string $reason): void
    {
        $label = $program?->name ? "Your voucher “{$program->name}”" : 'Your voucher';
        $why = match (true) {
            ! $voucher || ! $program => 'it is no longer available at this spa',
            ! $voucher->isUsable() => $voucher->status === 'used' ? 'it had already been used' : 'it had expired',
            $reason !== null && $reason !== '' => rtrim($reason, '.'),
            default => 'it could not be used on this visit',
        };
        $stays = $voucher && $program && $voucher->isUsable() ? ' The voucher is still in your wallet.' : '';

        app(ClientNotifier::class)->appointment(
            $appointment,
            "Your voucher wasn't applied",
            "{$label} couldn't be applied to your bill for booking {$appointment->appointment_number}: {$why}. The bill shows the full price.{$stays} If you think this is a mistake, please ask the spa.",
        );
    }

    // ── Void / refund ─────────────────────────────────────────────────────

    // A payment entered by mistake (wrong amount, wrong bill, duplicate tap).
    // Only same-day, not-yet-refunded payments; the bill's balance reopens.
    // Not on a finished visit: once it is paid in full it is locked
    // (FinishedVisit), so this is for a part payment on a visit still open.
    public function voidPayment(User $user, string $paymentUuid, string $reason, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        return DB::transaction(function () use ($user, $paymentUuid, $reason, $request) {
            $payment = Payment::where('uuid', $paymentUuid)
                ->whereIn('spa_branch_id', $this->branchIds($user))
                ->lockForUpdate()
                ->firstOrFail();
            $billing = Billing::whereKey($payment->billing_id)->lockForUpdate()->firstOrFail();

            if ($payment->payment_status !== 'Paid') {
                return $this->reject("This payment is already {$payment->payment_status}.");
            }
            if ($locked = $this->finishedVisitProblem($billing)) {
                return $this->reject($locked);
            }
            if ((float) $payment->refunded_amount > 0) {
                return $this->reject('Part of this payment was refunded — record another refund instead of voiding it.');
            }
            if (! $payment->paid_at?->isToday()) {
                return $this->reject('Only today\'s payments can be voided. Use a refund for older ones.');
            }
            if ($billing->status === 'Refunded') {
                return $this->reject('This bill was refunded.');
            }

            $old = $payment->only(['payment_status', 'refund_reason']);
            $payment->update(['payment_status' => 'Voided', 'refund_reason' => $reason, 'voided_at' => now(), 'voided_by' => $user->id]);

            if ($billing->status === 'Paid' && $this->balance($billing) > 0) {
                $billing->update(['status' => 'Pending', 'paid_at' => null]);
                $this->rewards->onBillReopened($billing);
            }

            $this->auditLogRepository->record($user->id, 'payments', $payment->id, 'Cancel', $old, $payment->only(array_keys($old)), $request);
            $this->notify($billing, 'Payment voided', '₱' . number_format((float) $payment->amount, 2) . " {$payment->payment_method} payment on {$billing->billing_number} was voided — {$reason}.", $user);

            return new BillingResource($this->detailed($billing->uuid));
        });
    }

    // Money handed back to the client. Taken off the newest Paid payments
    // first (refunded_amount); once everything paid has been given back the
    // bill is closed as Refunded. A partial refund leaves the bill as it is.
    // Never on a finished visit (FinishedVisit) — in practice this is what
    // was paid on a booking that was then cancelled, or a part payment.
    // With $serviceUuid it is the money for ONE service of the visit (the
    // checkout's per-service refund): at most what that service has been paid
    // and not yet had handed back (App\Support\ServiceRefunds), and it is
    // noted on the service so the same one is not refunded twice.
    public function refund(User $user, string $billingUuid, float $amount, string $reason, ?Request $request = null, ?string $serviceUuid = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        return DB::transaction(function () use ($user, $billingUuid, $amount, $reason, $request, $serviceUuid) {
            $billing = $this->lockedBilling($user, $billingUuid);

            if (in_array($billing->status, ['Cancelled', 'Refunded'], true)) {
                return $this->reject("This bill is already {$billing->status}.");
            }
            if ($locked = $this->finishedVisitProblem($billing)) {
                return $this->reject($locked);
            }

            $refundable = $this->paymentRepository->paidTotalForBilling($billing->id);
            $amount = round($amount, 2);
            if ($refundable <= 0) {
                return $this->reject('Nothing has been paid on this bill yet.');
            }
            if ($amount > $refundable + 0.004) {
                return $this->reject('You can refund at most ₱' . number_format($refundable, 2) . '.');
            }

            $service = null;
            if ($serviceUuid !== null) {
                if (! AppointmentServiceItem::tracksRefunds()) {
                    return $this->reject('Refunding a single service is not set up on this server yet.');
                }
                $service = AppointmentServiceItem::where('uuid', $serviceUuid)->where('appointment_id', $billing->appointment_id)->lockForUpdate()->first();
                if (! $service || $service->status === 'Cancelled') {
                    return $this->reject('That service is not on this bill.');
                }
                $left = $this->serviceRefunds($billing)[$service->id]['refundable'] ?? 0.0;
                if ($amount > $left + 0.004) {
                    return $this->reject('You can refund at most ₱' . number_format($left, 2) . ' for ' . ($service->displayName() ?? 'this service') . '.');
                }
                $reason = trim(($service->displayName() ?? 'Service') . ': ' . $reason);
            }

            $this->giveBack($billing, $amount, $reason, $user, $request);
            $service?->update(['refunded_amount' => round((float) $service->refunded_amount + $amount, 2)]);

            $this->notify($billing, 'Refund issued', '₱' . number_format($amount, 2) . " refunded on {$billing->billing_number} — {$reason}.", $user);

            return new BillingResource($this->detailed($billing->uuid));
        });
    }

    /**
     * What of this bill's money belongs to each of its services, and what can
     * still be handed back for each (App\Support\ServiceRefunds): service id =>
     * charge, net, paid, refunded, refundable. Empty for a bill that is not for
     * a visit.
     *
     * @return array<int, array{charge: float, net: float, paid: float, refunded: float, refundable: float}>
     */
    public function serviceRefunds(Billing $billing): array
    {
        if (! $billing->appointment_id) {
            return [];
        }
        $appointment = Appointment::with(['services' => fn ($q) => $q->where('status', '!=', 'Cancelled'), 'packages'])->find($billing->appointment_id);
        if (! $appointment) {
            return [];
        }

        $packages = $appointment->packages->where('status', '!=', 'Cancelled');
        $lines = [];
        foreach ($appointment->services as $service) {
            $refunded = AppointmentServiceItem::tracksRefunds() ? (float) $service->refunded_amount : 0.0;
            if ($service->source_appointment_package_id === null) {
                // On its own: its price, less its own discount.
                $charge = (float) $service->subtotal - (float) $service->discount_amount;
            } else {
                // From a package: its share of the package price (by list
                // price) and what an extension adds on top.
                $package = $packages->firstWhere('id', $service->source_appointment_package_id);
                $weights = $appointment->services->where('source_appointment_package_id', $service->source_appointment_package_id)
                    ->mapWithKeys(fn ($l) => [$l->id => max(0, (float) $l->unit_price * max(1, (int) $l->quantity) - (float) ($l->extra_charge ?? 0))])->all();
                $charge = ($package ? PackageShare::of((float) $package->subtotal - (float) $package->discount_amount, $weights, $service->id) : 0.0)
                    + (float) ($service->extra_charge ?? 0);
            }
            $lines[] = ['id' => $service->id, 'charge' => $charge, 'refunded' => $refunded];
        }

        $payments = Payment::where('billing_id', $billing->id)->whereIn('payment_status', ['Paid', 'Refunded'])->get();

        return ServiceRefunds::plan(
            $lines,
            (float) $appointment->home_service_fee,
            (float) $billing->discount_amount,
            (float) $payments->sum('amount'),
            (float) $payments->sum(fn ($p) => (float) $p->amount - (float) $p->refunded_amount),
        );
    }

    /**
     * Hands $amount back off a bill already checked and locked by the caller
     * (inside its transaction): taken off the newest Paid payments first
     * (refunded_amount, audited); once everything paid has been given back
     * the bill is closed as Refunded — unless $closeWhenEmpty is false (a
     * voucher that makes the bill free leaves it Paid, at ₱0).
     */
    public function giveBack(Billing $billing, float $amount, string $reason, User $user, ?Request $request = null, bool $closeWhenEmpty = true): void
    {
        $left = round($amount, 2);
        $payments = Payment::where('billing_id', $billing->id)->where('payment_status', 'Paid')->orderByDesc('paid_at')->lockForUpdate()->get();
        foreach ($payments as $payment) {
            if ($left <= 0) {
                break;
            }
            $available = (float) $payment->amount - (float) $payment->refunded_amount;
            $take = min($available, $left);
            if ($take <= 0) {
                continue;
            }
            $old = $payment->only(['refunded_amount', 'refund_reason', 'payment_status']);
            $refunded = round((float) $payment->refunded_amount + $take, 2);
            $payment->update([
                'refunded_amount' => $refunded,
                'refund_reason' => $reason,
                'payment_status' => $refunded + 0.004 >= (float) $payment->amount ? 'Refunded' : 'Paid',
            ]);
            $this->auditLogRepository->record($user->id, 'payments', $payment->id, 'Refund', $old, $payment->only(array_keys($old)), $request);
            $left = round($left - $take, 2);
        }

        if ($closeWhenEmpty && $this->paymentRepository->paidTotalForBilling($billing->id) <= 0.004) {
            $billing->update(['status' => 'Refunded']);
            $this->rewards->onBillReopened($billing, refunded: true);
        }
    }

    // ── After payment (App\Service\Business\BillingAdjustmentService) ─────

    /**
     * A refund before payment: $amount comes off what is owed on the open bill
     * (locked by the caller, inside its transaction) — credit_amount, audited.
     * Settles the bill when nothing is left to pay.
     */
    public function applyCredit(Billing $billing, float $amount, User $user, ?Request $request = null): void
    {
        $old = $billing->only(['credit_amount', 'amount']);
        $billing->update([
            'credit_amount' => round((float) $billing->credit_amount + $amount, 2),
            'amount' => round(max(0, (float) $billing->amount - $amount), 2),
        ]);
        $this->auditLogRepository->record($user->id, 'billings', $billing->id, 'Update', $old, $billing->only(array_keys($old)), $request);
        $this->settle($billing->fresh());
    }

    /** What was paid on the bill and not handed back. */
    public function kept(Billing $billing): float
    {
        return $this->paymentRepository->paidTotalForBilling($billing->id);
    }

    /** What the client paid on the bill, before anything was handed back (voided payments left out). */
    public function paidGross(Billing $billing): float
    {
        return round((float) Payment::where('billing_id', $billing->id)->whereIn('payment_status', ['Paid', 'Refunded'])->sum('amount'), 2);
    }

    /**
     * One of the client's vouchers put on a paid bill: what it would take off
     * and hand back (App\Support\BillAdjustment::voucher), or why it can't.
     * Call inside a transaction when it is about to be applied (the voucher
     * row is locked).
     *
     * @return array{program: CustomerProgram, voucher: ClientVoucher, type: string, value: float, discount: float, amount: float, give_back: float}|string
     */
    public function voucherAfterPayment(Billing $billing, string $voucherUuid): array|string
    {
        // Only a voucher the client holds — not a first-visit one to claim
        // (voucherForBill would give it to them on the spot).
        $clientId = $billing->appointment?->client_id;
        if (! $clientId || ! ClientVoucher::where('uuid', $voucherUuid)->where('client_id', $clientId)->exists()) {
            return 'That voucher doesn\'t belong to this client.';
        }
        $picked = $this->voucherForBill($billing, $voucherUuid);
        if (is_string($picked)) {
            return $picked;
        }
        [$program, $voucher] = $picked;
        if ($billing->client_voucher_id === $voucher->id) {
            return 'That voucher is already on this bill.';
        }
        $derived = $this->programDiscount($billing, $program);
        if (is_string($derived)) {
            return $derived;
        }
        [$type, $value] = $derived;

        $money = \App\Support\BillAdjustment::voucher(
            (float) ($billing->subtotal ?? $billing->amount),
            (float) $billing->discount_amount,
            $this->kept($billing),
            $type,
            (float) $value,
            (float) ($billing->credit_amount ?? 0),
        );
        if (is_string($money)) {
            return $money;
        }

        return ['program' => $program, 'voucher' => $voucher, 'type' => $type, 'value' => (float) $value] + $money;
    }

    /**
     * Puts the voucher on the paid bill (locked by the caller, inside its
     * transaction) in place of any discount on it, and hands the difference
     * back. A voucher that was on the bill goes back to the client's wallet.
     *
     * @return float|string  what was handed back, or why it couldn't be done
     */
    public function applyVoucherAfterPayment(Billing $billing, string $voucherUuid, string $reason, User $user, ?Request $request = null): float|string
    {
        $plan = $this->voucherAfterPayment($billing, $voucherUuid);
        if (is_string($plan)) {
            return $plan;
        }

        if ($billing->client_voucher_id) {
            ClientVoucher::whereKey($billing->client_voucher_id)->where('status', 'used')
                ->update(['status' => 'available', 'used_at' => null, 'used_billing_id' => null, 'used_by' => null]);
        }

        $subtotal = (float) ($billing->subtotal ?? $billing->amount);
        $old = $billing->only(['subtotal', 'discount_type', 'discount_value', 'discount_amount', 'discount_reason', 'amount']);
        $billing->update($this->discountFields($subtotal, $plan['type'], $plan['value'], $plan['discount'], 'Voucher: ' . $plan['program']->name, $user->id, $plan['program'], $plan['voucher'], (float) ($billing->credit_amount ?? 0)));
        $plan['voucher']->update(['status' => 'used', 'used_at' => now(), 'used_billing_id' => $billing->id, 'used_by' => $user->id]);
        $this->auditLogRepository->record($user->id, 'billings', $billing->id, 'Update', $old, $billing->only(array_keys($old)), $request);

        $this->giveBack($billing, $plan['give_back'], 'Voucher after payment: ' . $plan['program']->name . ' — ' . $reason, $user, $request, closeWhenEmpty: false);

        return $plan['give_back'];
    }

    /** The bill as the detail screens read it (BillingResource with everything loaded). */
    public function present(string $billingUuid): BillingResource
    {
        return new BillingResource($this->detailed($billingUuid));
    }

    // ── Customer programs at checkout ─────────────────────────────────────

    // What the front desk can apply to this bill: discount programs offered
    // at its branch (members-only ones when the client's membership bundles
    // them) and the client's usable vouchers — each with its computed value.
    public function programOptions(User $user, string $billingUuid)
    {
        $billing = Billing::where('uuid', $billingUuid)->whereIn('spa_branch_id', $this->branchIds($user))->firstOrFail();
        if (! $billing->spa_branch_id || ! $this->rewards->enabledFor($billing->spa_business_id)) {
            return ['data' => ['enabled' => false, 'discounts' => [], 'vouchers' => []]];
        }

        $client = $billing->appointment?->client;
        // A member's perks are the ones the membership was sold with
        // (ClientMembership::held / App\Support\History).
        $membership = $client?->activeMembership()->with('program.items')->first();
        $memberItemIds = $membership?->perkIds() ?? [];

        // Vouchers on another booking the client made in the app can't be used
        // here; the one made for this booking is flagged.
        $heldElsewhere = $client ? ClientVoucher::heldIds(ClientVoucher::where('client_id', $client->id)->pluck('id'), $billing->appointment_id) : [];
        $reservedHere = Appointment::holdsVouchers() ? $billing->appointment?->client_voucher_id : null;

        // Where this visit was booked: a program limited to the app or the
        // desk is greyed out on a bill from the other one.
        $where = ProgramChannel::whereOf($billing->appointment);
        $membershipHere = ! $membership?->program || ProgramChannel::allows(ProgramChannel::of($membership->program), $where);

        $describe = function (CustomerProgram $program, ?ClientVoucher $voucher = null, bool $claimable = false) use ($billing, $heldElsewhere, $reservedHere, $where, $memberItemIds, $membershipHere) {
            $derived = $this->programDiscount($billing, $program);
            $elsewhere = $voucher && in_array($voucher->id, $heldElsewhere, true);
            $channel = ProgramChannel::of($program);
            $wrongChannel = ! ProgramChannel::allows($channel, $where)
                || (! $membershipHere && ($voucher?->client_membership_id || in_array($program->id, $memberItemIds, true)));

            return [
                'uuid' => $voucher?->uuid ?? $program->uuid,
                'program_uuid' => $program->uuid,
                'name' => $program->name,
                'type' => is_string($derived) ? null : $derived[0],
                'value' => is_string($derived) ? null : $derived[1],
                'unavailable_reason' => $wrongChannel
                    ? ProgramChannel::refusalAt($where)
                    : ($elsewhere ? 'Reserved for another booking' : (is_string($derived) ? $derived : null)),
                'channel' => $channel,
                // A first-visit voucher not claimed yet: it is claimed when applied.
                'claimable' => $claimable,
                // The voucher the client booked this visit with.
                'reserved' => $voucher !== null && $voucher->id === $reservedHere,
                // "Free service" | "% off" | "₱ off" for a voucher.
                'deal_type' => $voucher || $claimable ? $program->value('dealType', '₱ off') : null,
                'expires_at' => $voucher?->expires_at?->toIso8601String(),
                'members_only' => $program->audience === 'members',
            ];
        };

        $discounts = $this->rewards->offeredAt($billing->spa_business_id, $billing->spa_branch_id, 'discount')
            ->filter(fn ($p) => $p->audience !== 'members' || in_array($p->id, $memberItemIds, true))
            ->map(fn ($p) => $describe($membership ? $membership->held($p) : $p))->values();
        // A discount the membership was sold with stays the member's, even
        // if it has since been switched off or removed.
        foreach ($membership?->heldDiscounts() ?? [] as $held) {
            if (! $discounts->contains('program_uuid', $held->uuid)) {
                $discounts->push($describe($held));
            }
        }

        $vouchers = $client
            ? ClientVoucher::with('program')->where('client_id', $client->id)->where('status', 'available')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderBy('expires_at')->get()
                // A voucher is the deal it was issued with (its own copy),
                // usable until it is used or expires — whatever has since
                // happened to the program it came from.
                ->filter(fn ($v) => $v->dealProgram() !== null)
                ->map(fn ($v) => $describe($v->dealProgram(), $v))->values()
            : collect();

        // The first-visit voucher a client with no paid visit yet can still
        // claim for this bill: listed with the rest, claimed when applied.
        if ($client) {
            foreach ($this->rewards->offeredAt($billing->spa_business_id, $billing->spa_branch_id, 'voucher') as $program) {
                if ($program->condition_kind === 'first_visit' && $this->rewards->firstVisitClaimable($client, $program)) {
                    $vouchers->push($describe($program, null, true));
                }
            }
            $vouchers = $vouchers->values();
        }

        return ['data' => ['enabled' => true, 'discounts' => $discounts, 'vouchers' => $vouchers]];
    }

    /** @return array{0: CustomerProgram, 1: ClientVoucher}|string */
    private function voucherForBill(Billing $billing, string $voucherUuid): array|string
    {
        $clientId = $billing->appointment?->client_id;
        $voucher = $clientId ? ClientVoucher::with('program')->where('uuid', $voucherUuid)->where('client_id', $clientId)->lockForUpdate()->first() : null;
        $where = ProgramChannel::whereOf($billing->appointment);
        // Not a voucher the client holds: the id of a first-visit voucher they
        // can still claim. It is given to them now, then used like any other.
        if (! $voucher && $clientId && ($firstVisit = CustomerProgram::where('uuid', $voucherUuid)->where('spa_business_id', $billing->spa_business_id)->where('condition_kind', 'first_visit')->first())) {
            $voucher = $this->rewards->claimFirstVisit($billing->appointment->client, $firstVisit, $where, $billing->spa_branch_id);
            if (! $voucher) {
                return 'That first-visit voucher isn\'t available to this client.';
            }
            $voucher->load('program');
        }
        $program = $voucher?->dealProgram();
        if (! $voucher || ! $program) {
            return 'That voucher doesn\'t belong to this client.';
        }
        if (! $this->rewards->voucherAllowedAt($voucher, $where)) {
            return 'That voucher can\'t be used on this bill. ' . ProgramChannel::refusalAt($where);
        }
        if ($billing->client_voucher_id !== $voucher->id && ! $voucher->isUsable()) {
            return $voucher->status === 'used' ? 'That voucher has already been used.' : 'That voucher has expired.';
        }
        if (ClientVoucher::heldIds([$voucher->id], $billing->appointment_id) !== []) {
            return 'That voucher is reserved for another booking.';
        }

        return [$program, $voucher];
    }

    /** @return array{0: CustomerProgram, 1: null}|string */
    private function discountProgramForBill(Billing $billing, string $programUuid): array|string
    {
        $membership = $billing->appointment?->client?->activeMembership()->with('program.items')->first();
        $program = CustomerProgram::where('uuid', $programUuid)
            ->where('spa_business_id', $billing->spa_business_id)
            ->where('type', 'discount')->where('is_active', true)->first();
        if (! $program || ! $program->offeredAt($billing->spa_branch_id)) {
            // No longer on offer — unless this client's membership was sold with it.
            $held = $membership?->heldDiscounts()->firstWhere('uuid', $programUuid);

            return $held ? [$held, null] : 'That discount isn\'t offered at this branch.';
        }
        if ($program->audience === 'members' && ! in_array($program->id, $membership?->perkIds() ?? [], true)) {
            return 'That discount is for members — this client\'s membership doesn\'t include it.';
        }
        $where = ProgramChannel::whereOf($billing->appointment);
        if (! ProgramChannel::allows(ProgramChannel::of($program), $where)
            || ($membership?->program && in_array($program->id, $membership->perkIds(), true) && ! ProgramChannel::allows(ProgramChannel::of($membership->program), $where))) {
            return 'That discount can\'t be used on this bill. ' . ProgramChannel::refusalAt($where);
        }

        return [$membership ? $membership->held($program) : $program, null];
    }

    // The discount programs a client's running membership bundles.
    private function memberItemIds($client): array
    {
        $membership = $client?->activeMembership()->with('program.items')->first();

        return $membership?->perkIds() ?? [];
    }

    /** @return array{0: string, 1: float}|string [type, value], or why it can't apply */
    private function programDiscount(Billing $billing, CustomerProgram $program): array|string
    {
        if ($program->type === 'discount') {
            $amount = (float) $program->value('percent', 0);

            return $amount > 0 ? [$program->value('kind') === '₱' ? 'amount' : 'percent', $amount] : 'This discount has no amount set.';
        }

        $deal = $program->value('dealType', '₱ off');
        $target = VoucherTarget::of($program->values, 'usable');

        // A voucher for a category or for chosen services comes off one
        // service — the dearest one on the bill it is for — never the whole
        // bill. A service inside a package counts too, at what the package
        // says it is worth (its line's price).
        if (! VoucherTarget::isAll($target)) {
            $value = (float) $program->value('value', 0);
            if ($deal !== 'Free service' && $value <= 0) {
                return 'This voucher has no amount set.';
            }
            $lines = $billing->appointment?->services()
                ->with('serviceVariant.service')
                ->where('status', '!=', 'Cancelled')
                ->get()
                ->map(fn ($item) => [
                    'uuid' => $item->serviceVariant?->service?->uuid,
                    'category' => $item->category(),
                    'price' => (float) $item->unit_price,
                ])->all() ?? [];
            $line = VoucherTarget::priciest($target, $lines);

            return $line
                ? ['amount', VoucherTarget::amountOff($deal, $value, (float) $line['price'])]
                : 'This voucher is for ' . $program->value('usableOn', 'certain services') . ' — this bill has none.';
        }

        if ($deal === 'Free service') {
            $name = trim((string) $program->value('usableOn', ''));
            $line = $name === '' ? null : $billing->appointment?->services()
                ->with('serviceVariant.service')
                ->where('status', '!=', 'Cancelled')
                ->get()
                ->filter(fn ($item) => str_contains(mb_strtolower($item->displayName() ?? ''), mb_strtolower($name)))
                ->sortByDesc(fn ($item) => (float) $item->unit_price)
                ->first();

            return $line ? ['amount', (float) $line->unit_price] : "This bill has no {$name} to make free.";
        }

        $amount = (float) $program->value('value', 0);

        return $amount > 0 ? [$deal === '% off' ? 'percent' : 'amount', $amount] : 'This voucher has no amount set.';
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function detailed(string $billingUuid): Billing
    {
        return Billing::with([
            'payments.receiver',
            'appointment.client',
            'appointment.branch',
            'appointment.services.serviceVariant.service',
            'appointment.services.therapistAssignments.staff',
            'appointment.services.therapistAssignments.facility',
            // The package lines of the bill (see AppointmentService::getBilling).
            'appointment.services.sourceAppointmentPackage.package',
            'appointment.packages.package',
        ])->where('uuid', $billingUuid)->firstOrFail();
    }

    // A finished, paid visit can't be voided or refunded (FinishedVisit).
    // Bills that aren't for a visit (subscriptions, memberships) have none.
    private function finishedVisitProblem(Billing $billing): ?string
    {
        return $billing->appointment_id
            ? FinishedVisit::problem(Appointment::whereKey($billing->appointment_id)->value('status'))
            : null;
    }

    private function lockedBilling(User $user, string $billingUuid): Billing
    {
        return Billing::where('uuid', $billingUuid)
            ->whereIn('spa_branch_id', $this->branchIds($user))
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function notify(Billing $billing, string $title, string $message, User $actor): void
    {
        $billing->loadMissing('branch');
        if ($billing->branch) {
            // Money going back out is the owner's and manager's business,
            // not the rest of the front desk's.
            $this->staffNotifier->branch($billing->branch, $title, $message, type: 'Payment', roles: ['manager'], exceptUserId: $actor->id, reference: $billing->appointment?->uuid);
        }
    }

    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchIdsForUser($user);
    }

    private function reject(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 422);
    }

    private function noBusiness(): JsonResponse
    {
        return $this->reject('No spa business found for this account.');
    }
}
