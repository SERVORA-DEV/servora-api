<?php

namespace App\Support;

// Changing a bill after it is paid: a voucher put on afterwards (its value
// comes off and the difference is handed back) or a refund, part or all.
// And before it is paid: a refund that takes money off what the client owes
// ("credit"), for one service or the whole bill.
//
// Who has to agree depends on who is doing it:
//   - the owner never needs anyone;
//   - a manager or front desk account the owner gave "Adjust paid bills"
//     (permission payment_adjust) applies it at once;
//   - without it, it becomes a request for someone higher up:
//       front desk → a manager of the branch (one who could make it
//                    themselves) or the owner;
//       manager    → the owner.
//
// The money side (what a voucher hands back) is worked out here too, from
// the bill's own numbers.
//
// Pure — tested in tests/Unit/BillAdjustmentTest. Applied by
// App\Service\Business\BillingAdjustmentService.
class BillAdjustment
{
    public const VOUCHER = 'voucher';
    public const REFUND = 'refund';
    // Before payment: taken off what is owed; nothing is handed back.
    public const CREDIT = 'credit';
    public const KINDS = [self::VOUCHER, self::REFUND, self::CREDIT];

    /** Whether this kind moves money back to the client (refund) or off the bill (credit) under a refund policy. */
    public static function isRefundLike(string $kind): bool
    {
        return $kind === self::REFUND || $kind === self::CREDIT;
    }

    // What route() answers: go ahead now, or who has to approve.
    public const NOW = 'now';
    public const MANAGER = 'manager';
    public const OWNER = 'owner';

    public const MIN_REASON = 3;

    /**
     * @param  string  $role  the account's role: business_owner | manager | front_officer
     * @param  bool  $allowed  the account has "Adjust paid bills" on
     * @param  bool  $mustAsk  the refund policy used always needs approval (RefundPolicyRule)
     * @return string  NOW, MANAGER (a manager or the owner approves) or OWNER (only the owner)
     */
    public static function route(string $role, bool $allowed, bool $mustAsk = false): string
    {
        if ($role === 'business_owner') {
            return self::NOW;
        }
        if ($allowed && ! $mustAsk && in_array($role, ['manager', 'front_officer'], true)) {
            return self::NOW;
        }

        return $role === 'front_officer' ? self::MANAGER : self::OWNER;
    }

    /**
     * Whether this person may approve or decline a request that needs $needs.
     * A manager approving is the manager making it, so they need to be allowed
     * to make it themselves, and to manage the branch it is at. Nobody answers
     * their own request.
     */
    public static function mayDecide(string $role, bool $allowed, string $needs, bool $managesBranch, bool $isRequester): bool
    {
        if ($isRequester) {
            return false;
        }
        if ($role === 'business_owner') {
            return true;
        }

        return $role === 'manager' && $needs === self::MANAGER && $allowed && $managesBranch;
    }

    /** Why a reason is not enough, or null. */
    public static function reasonProblem(?string $reason): ?string
    {
        return mb_strlen(trim((string) $reason)) < self::MIN_REASON ? 'Give a reason for the change.' : null;
    }

    /** Why the bill can't be changed this way at all, or null. */
    public static function billProblem(string $billStatus, ?string $billingType): ?string
    {
        if ($billingType !== null && $billingType !== 'Appointment') {
            return 'Only a visit\'s bill can be adjusted.';
        }
        if ($billStatus === 'Refunded') {
            return 'This bill has already been refunded in full.';
        }
        if ($billStatus !== 'Paid') {
            return 'Only a paid bill can be adjusted. Use the checkout while it is still open.';
        }

        return null;
    }

    /** Why money can't be taken off this bill before payment, or null. */
    public static function openBillProblem(string $billStatus, ?string $billingType): ?string
    {
        if ($billingType !== null && $billingType !== 'Appointment') {
            return 'Only a visit\'s bill can be refunded.';
        }
        if (in_array($billStatus, ['Paid', 'Refunded', 'Cancelled'], true)) {
            return $billStatus === 'Paid'
                ? 'This bill is already paid — use Adjust to refund it.'
                : "This bill is {$billStatus}.";
        }

        return null;
    }

    /**
     * Why this much can't be taken off before payment, or null.
     *
     * @param  float  $owed  what is still owed (the total less what has been paid)
     * @param  ?float  $serviceLeft  for one service: its charge less what was already taken off it
     */
    public static function creditProblem(float $amount, float $owed, ?float $serviceLeft = null): ?string
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return 'Enter the amount to take off.';
        }
        if ($serviceLeft !== null && $amount > $serviceLeft + 0.004) {
            return $serviceLeft <= 0.004
                ? 'Everything on this service has already been taken off.'
                : 'You can take off at most ₱' . number_format($serviceLeft, 2) . ' for this service.';
        }
        if ($amount > $owed + 0.004) {
            return $owed <= 0.004
                ? 'Nothing is left to pay on this bill.'
                : 'You can take off at most ₱' . number_format($owed, 2) . ' — the rest has already been paid.';
        }

        return null;
    }

    /** Why this refund can't be given, or null. $kept is what was paid and not yet handed back. */
    public static function refundProblem(float $amount, float $kept): ?string
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return 'Enter the amount to refund.';
        }
        if ($kept <= 0.004) {
            return 'Everything paid on this bill has already been handed back.';
        }
        if ($amount > $kept + 0.004) {
            return 'You can refund at most ₱' . number_format($kept, 2) . '.';
        }

        return null;
    }

    /**
     * A voucher put on a paid bill. One discount per bill: the voucher takes
     * the place of any discount already on it, so it has to be worth more.
     *
     * @param  float  $subtotal  the bill before any discount
     * @param  float  $currentDiscount  the discount on it now
     * @param  float  $kept  what was paid and not yet handed back
     * @param  string  $type  the voucher's 'amount' (₱) or 'percent'
     * @param  float  $value  its ₱ or %
     * @param  float  $credit  what was taken off the bill before payment (stays off)
     * @return array{discount: float, amount: float, give_back: float}|string
     *         the new discount and total, and what goes back to the client; or why not
     */
    public static function voucher(float $subtotal, float $currentDiscount, float $kept, string $type, float $value, float $credit = 0.0): array|string
    {
        $discount = $type === 'percent'
            ? round($subtotal * min(max($value, 0), 100) / 100, 2)
            : round(min(max($value, 0), $subtotal), 2);

        if ($discount <= 0) {
            return 'This voucher takes nothing off this bill.';
        }
        if ($discount <= $currentDiscount + 0.004) {
            return 'This voucher takes off ₱' . number_format($discount, 2) . ', no more than the ₱'
                . number_format($currentDiscount, 2) . ' discount already on the bill — there is nothing to hand back.';
        }

        $amount = round(max(0, $subtotal - $discount - $credit), 2);
        $giveBack = round(min($kept, $kept - $amount), 2);
        if ($giveBack <= 0.004) {
            return 'What this voucher takes off has already been handed back on this bill.';
        }

        return ['discount' => $discount, 'amount' => $amount, 'give_back' => $giveBack];
    }
}
