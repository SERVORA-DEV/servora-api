<?php

namespace App\Service\Business;

use App\Http\Resources\BillingResource;
use App\Http\Resources\PaymentResource;
use App\Models\Appointment;
use App\Models\Billing;
use App\Models\ClientVoucher;
use App\Models\CustomerProgram;
use App\Models\Payment;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\PaymentRepository;
use App\Repository\SpaBusinessRepository;
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
        'accept_xendit' => ['Online Banking', 'QR Code'],
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
            $this->clientNotifier->appointment(
                $appointment,
                'Thanks for visiting',
                "How was your visit to {$appointment->branch?->branch_name}? Tap to rate it.",
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

            if ($subtotal - $discount + 0.004 < $paid) {
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
            $billing->update($value > 0 ? [
                'subtotal' => $subtotal,
                'discount_type' => $type,
                'discount_value' => $value,
                'discount_amount' => $discount,
                'discount_reason' => $reason,
                'discounted_by' => $user->id,
                'discount_program_id' => $program?->id,
                'client_voucher_id' => $voucher?->id,
                'amount' => round($subtotal - $discount, 2),
            ] : [
                'subtotal' => $subtotal,
                'discount_type' => null,
                'discount_value' => null,
                'discount_amount' => 0,
                'discount_reason' => null,
                'discounted_by' => null,
                'discount_program_id' => null,
                'client_voucher_id' => null,
                'amount' => $subtotal,
            ]);

            if ($voucher && $value > 0) {
                $voucher->update(['status' => 'used', 'used_at' => now(), 'used_billing_id' => $billing->id, 'used_by' => $user->id]);
            }

            $this->auditLogRepository->record($user->id, 'billings', $billing->id, 'Update', $old, $billing->only(array_keys($old)), $request);
            $this->settle($billing->fresh());

            return new BillingResource($this->detailed($billing->uuid));
        });
    }

    // ── Void / refund ─────────────────────────────────────────────────────

    // A payment entered by mistake (wrong amount, wrong bill, duplicate tap).
    // Only same-day, not-yet-refunded payments; the bill's balance reopens.
    // The appointment keeps its status — the visit still happened.
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
    public function refund(User $user, string $billingUuid, float $amount, string $reason, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        return DB::transaction(function () use ($user, $billingUuid, $amount, $reason, $request) {
            $billing = $this->lockedBilling($user, $billingUuid);

            if (in_array($billing->status, ['Cancelled', 'Refunded'], true)) {
                return $this->reject("This bill is already {$billing->status}.");
            }

            $refundable = $this->paymentRepository->paidTotalForBilling($billing->id);
            $amount = round($amount, 2);
            if ($refundable <= 0) {
                return $this->reject('Nothing has been paid on this bill yet.');
            }
            if ($amount > $refundable + 0.004) {
                return $this->reject('You can refund at most ₱' . number_format($refundable, 2) . '.');
            }

            $left = $amount;
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

            if ($this->paymentRepository->paidTotalForBilling($billing->id) <= 0.004) {
                $billing->update(['status' => 'Refunded']);
                $this->rewards->onBillReopened($billing, refunded: true);
            }

            $this->notify($billing, 'Refund issued', '₱' . number_format($amount, 2) . " refunded on {$billing->billing_number} — {$reason}.", $user);

            return new BillingResource($this->detailed($billing->uuid));
        });
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
        $memberItemIds = $this->memberItemIds($client);

        $describe = function (CustomerProgram $program, ?ClientVoucher $voucher = null) use ($billing) {
            $derived = $this->programDiscount($billing, $program);

            return [
                'uuid' => $voucher?->uuid ?? $program->uuid,
                'program_uuid' => $program->uuid,
                'name' => $program->name,
                'type' => is_string($derived) ? null : $derived[0],
                'value' => is_string($derived) ? null : $derived[1],
                'unavailable_reason' => is_string($derived) ? $derived : null,
                'expires_at' => $voucher?->expires_at?->toIso8601String(),
                'members_only' => $program->audience === 'members',
            ];
        };

        $discounts = $this->rewards->offeredAt($billing->spa_business_id, $billing->spa_branch_id, 'discount')
            ->filter(fn ($p) => $p->audience !== 'members' || in_array($p->id, $memberItemIds, true))
            ->map(fn ($p) => $describe($p))->values();

        $vouchers = $client
            ? ClientVoucher::with('program')->where('client_id', $client->id)->where('status', 'available')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderBy('expires_at')->get()
                ->filter(fn ($v) => $v->program && ! $v->program->trashed())
                ->map(fn ($v) => $describe($v->program, $v))->values()
            : collect();

        return ['data' => ['enabled' => true, 'discounts' => $discounts, 'vouchers' => $vouchers]];
    }

    /** @return array{0: CustomerProgram, 1: ClientVoucher}|string */
    private function voucherForBill(Billing $billing, string $voucherUuid): array|string
    {
        $clientId = $billing->appointment?->client_id;
        $voucher = $clientId ? ClientVoucher::with('program')->where('uuid', $voucherUuid)->where('client_id', $clientId)->lockForUpdate()->first() : null;
        if (! $voucher || ! $voucher->program) {
            return 'That voucher doesn\'t belong to this client.';
        }
        if ($billing->client_voucher_id !== $voucher->id && ! $voucher->isUsable()) {
            return $voucher->status === 'used' ? 'That voucher has already been used.' : 'That voucher has expired.';
        }

        return [$voucher->program, $voucher];
    }

    /** @return array{0: CustomerProgram, 1: null}|string */
    private function discountProgramForBill(Billing $billing, string $programUuid): array|string
    {
        $program = CustomerProgram::where('uuid', $programUuid)
            ->where('spa_business_id', $billing->spa_business_id)
            ->where('type', 'discount')->where('is_active', true)->first();
        if (! $program || ! $program->offeredAt($billing->spa_branch_id)) {
            return 'That discount isn\'t offered at this branch.';
        }
        if ($program->audience === 'members' && ! in_array($program->id, $this->memberItemIds($billing->appointment?->client), true)) {
            return 'That discount is for members — this client\'s membership doesn\'t include it.';
        }

        return [$program, null];
    }

    // The discount programs a client's running membership bundles.
    private function memberItemIds($client): array
    {
        $membership = $client?->activeMembership()->with('program.items')->first();

        return $membership?->program?->items->pluck('id')->all() ?? [];
    }

    /** @return array{0: string, 1: float}|string [type, value], or why it can't apply */
    private function programDiscount(Billing $billing, CustomerProgram $program): array|string
    {
        if ($program->type === 'discount') {
            $amount = (float) $program->value('percent', 0);

            return $amount > 0 ? [$program->value('kind') === '₱' ? 'amount' : 'percent', $amount] : 'This discount has no amount set.';
        }

        $deal = $program->value('dealType', '₱ off');
        if ($deal === 'Free service') {
            $name = trim((string) $program->value('usableOn', ''));
            $line = $name === '' ? null : $billing->appointment?->services()
                ->with('serviceVariant.service')
                ->where('status', '!=', 'Cancelled')
                ->get()
                ->filter(fn ($item) => str_contains(mb_strtolower($item->serviceVariant?->service?->name ?? ''), mb_strtolower($name)))
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
        ])->where('uuid', $billingUuid)->firstOrFail();
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
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
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
