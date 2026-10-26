<?php

namespace App\Support;

// The owner's refund policies: the reasons a paid bill can be refunded for,
// and how much each one gives back (App\Models\RefundPolicy, set in Business
// Defaults → Refunds). The front desk picks one when it refunds a paid bill.
//
//   full     everything the client paid;
//   percent  a share of what the client paid;
//   fixed    a set amount.
//
// Never more than is still held (paid and not handed back). With allow_less
// the staff member may refund less than the policy's amount, never more.
// A policy marked needs_approval always goes up for approval, even from
// someone allowed to adjust paid bills (BillAdjustment::route) — except the
// owner, who answers to no one.
//
// A refund that fits no policy is allowed only while the owner leaves
// "Refunds outside these policies" on; the owner can always give one. Who a
// refund comes out of — the business, the staff, or equally — is
// App\Support\RefundCharge (the policy's choice, or the giver's outside them).
//
// Pure — tested in tests/Unit/RefundPolicyRuleTest.
class RefundPolicyRule
{
    public const FULL = 'full';
    public const PERCENT = 'percent';
    public const FIXED = 'fixed';
    public const MODES = [self::FULL, self::PERCENT, self::FIXED];

    /**
     * What the policy gives back on a bill.
     *
     * @param  float  $paid  what the client paid on the bill (before any refund)
     * @param  float  $kept  what is still held (paid and not handed back)
     */
    public static function amount(string $mode, ?float $value, float $paid, float $kept): float
    {
        $amount = match ($mode) {
            self::FULL => $paid,
            self::PERCENT => round($paid * min(max((float) $value, 0), 100) / 100, 2),
            default => round(max((float) $value, 0), 2),
        };

        return round(max(0, min($amount, $kept)), 2);
    }

    /** Why this amount can't be refunded under the policy, or null. */
    public static function problem(string $mode, ?float $value, bool $allowLess, float $paid, float $kept, float $requested): ?string
    {
        $most = self::amount($mode, $value, $paid, $kept);
        $requested = round($requested, 2);

        if ($most <= 0) {
            return 'This policy gives nothing back on this bill.';
        }
        if ($requested > $most + 0.004) {
            return 'This policy refunds at most ₱' . number_format($most, 2) . ' on this bill.';
        }
        if (! $allowLess && $requested + 0.004 < $most) {
            return 'This policy refunds exactly ₱' . number_format($most, 2) . ' on this bill.';
        }

        return null;
    }

    /** Why a refund outside the policies isn't allowed for this person, or null. */
    public static function customProblem(string $role, bool $customAllowed, bool $hasPolicies): ?string
    {
        if ($role === 'business_owner' || $customAllowed) {
            return null;
        }

        return $hasPolicies
            ? 'Pick one of the refund policies.'
            : 'Refunds outside the owner\'s refund policies are turned off, and none are set up yet.';
    }

    /** "50% of what was paid", "₱200", "Everything paid" — with ", or less" when allowed. */
    public static function describe(string $mode, ?float $value, bool $allowLess): string
    {
        $text = match ($mode) {
            self::FULL => 'Everything paid',
            self::PERCENT => rtrim(rtrim(number_format((float) $value, 2), '0'), '.') . '% of what was paid',
            default => '₱' . number_format((float) $value, 2),
        };

        return $mode !== self::FULL && $allowLess ? "Up to {$text}" : ($mode === self::FULL && $allowLess ? 'Up to everything paid' : $text);
    }

    /** Why the policy as the owner typed it can't be saved, or null. */
    public static function invalid(string $mode, ?float $value): ?string
    {
        if (! in_array($mode, self::MODES, true)) {
            return 'Pick how much this policy refunds.';
        }
        if ($mode === self::PERCENT && ($value === null || $value <= 0 || $value > 100)) {
            return 'Enter a share between 1 and 100%.';
        }
        if ($mode === self::FIXED && ($value === null || $value <= 0)) {
            return 'Enter the amount to refund.';
        }

        return null;
    }
}
