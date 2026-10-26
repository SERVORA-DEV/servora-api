<?php

namespace App\Support;

// What one therapist earns for one completed service, under the owner's
// Staff Policies as they stand at that moment:
//   - commission off                     → nothing;
//   - "applies to" services / packages   → a line of the other sort earns nothing;
//   - a fixed amount per service, or a percentage of the service's price;
//   - at the STANDARD rate (Staff Policies), unless this person has a custom
//     one of their own (staff.commission_rate) — the same kind as the
//     standard: a percentage when that is a percentage, pesos when fixed;
//   - shared equally by everyone who completed it, each at their own rate.
//
// It is worked out once, when the service is completed, and kept on the
// therapist's assignment (therapist_assignments.commission_amount) — changing
// the rate afterwards applies to services completed from then on, never to
// ones already done (App\Support\History).
//
// What was earned only SHOWS once the client has paid for the visit
// (paidFor): a service that is done but not yet paid for earns nothing a
// staff member can see, and neither does one whose payment was taken back.
//
// Pure — tested in tests/Unit/CommissionShareTest.
// App\Service\Business\CommissionCalculator applies and stores it.
class CommissionShare
{
    /**
     * Whether what was earned on a visit counts yet: only when its bill is
     * paid in full. `$billStatus` is the bill's status — null when the visit
     * has no bill yet (the services are done, checkout hasn't happened).
     *
     * 'Pending' covers a bill that is unpaid, part-paid, or reopened by a
     * voided payment; 'Refunded' one whose money went back to the client.
     */
    public static function paidFor(?string $billStatus): bool
    {
        return $billStatus === 'Paid';
    }

    /**
     * The base is the service line's own price, before anything a customer
     * program takes off the BILL (a voucher, a free service, a discount, a
     * membership perk, points): those live on the bill, never on the line, so
     * the owner absorbs them and the therapist is paid as if the service were
     * charged in full. Don't feed the bill's discount or amount in here.
     *
     * @param  ?array  $policy  Staff Policies, or null when commission is off
     * @param  float  $price  the service line's price (its subtotal)
     * @param  float  $listPrice  its list price × quantity, used when the line carries no price of its own (a package line)
     * @param  bool  $fromPackage  the line came out of a package
     * @param  int  $sharedBy  how many completed it
     * @param  ?float  $ownRate  the rate of this person's commission plan; null: the standard
     * @param  bool  $home  the service was done on a home visit: the home service commission replaces the plan's rate when the owner turned it on
     */
    public static function amount(?array $policy, float $price, float $listPrice, bool $fromPackage, int $sharedBy = 1, ?float $ownRate = null, bool $home = false): float
    {
        if ($policy === null || ! ($policy['commission_enabled'] ?? true)) {
            return 0.0;
        }

        $appliesTo = $policy['commission_applies_to'] ?? 'service';
        if (($fromPackage && $appliesTo === 'service') || (! $fromPackage && $appliesTo === 'package')) {
            return 0.0;
        }

        $rate = self::rate($policy, $ownRate, $home);
        $sharedBy = max(1, $sharedBy);

        if (self::type($policy, $home) === 'fixed') {
            return $rate / $sharedBy;
        }

        $base = $price > 0 ? $price : $listPrice;

        return $base * $rate / 100 / $sharedBy;
    }

    /** Whether a service done at home earns the home service commission instead of the plan's rate. */
    public static function homeApplies(?array $policy, bool $home): bool
    {
        return $home && (bool) ($policy['home_commission_enabled'] ?? false);
    }

    /**
     * The rate that applies to someone: the home service commission for a
     * service done at home (when the owner turned it on), else their plan's
     * rate, else the standard.
     */
    public static function rate(?array $policy, ?float $ownRate = null, bool $home = false): float
    {
        if (self::homeApplies($policy, $home)) {
            return (float) ($policy['home_commission_rate'] ?? 0);
        }

        return $ownRate ?? (float) ($policy['default_commission_rate'] ?? 0);
    }

    /** The kind that rate is in: 'percentage' or 'fixed'. */
    public static function type(?array $policy, bool $home = false): string
    {
        if (self::homeApplies($policy, $home)) {
            return ($policy['home_commission_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
        }

        return ($policy['commission_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
    }

    /**
     * A custom rate on its way to being saved: null for "standard" (nothing
     * sent, or blank); otherwise the number, which must fit the kind the
     * standard is.
     *
     * @return array{0: ?float, 1: ?string} the rate, and what is wrong with it (null: nothing)
     */
    public static function clean(mixed $sent, ?string $type): array
    {
        if ($sent === null || $sent === '') {
            return [null, null];
        }
        if (! is_numeric($sent) || (float) $sent < 0) {
            return [null, 'Enter a commission of 0 or more.'];
        }
        $rate = round((float) $sent, 2);
        if ($type !== 'fixed' && $rate > 100) {
            return [null, 'A percentage commission can be at most 100%.'];
        }

        return [$rate, null];
    }

    /** "20%" or "₱150 per service" — a rate in the words the screens use. */
    public static function label(float $rate, ?string $type): string
    {
        if ($type === 'fixed') {
            return '₱' . number_format($rate, fmod($rate, 1) ? 2 : 0) . ' per service';
        }

        return (fmod($rate, 1) ? rtrim(number_format($rate, 2), '0') : number_format($rate, 0)) . '%';
    }

    /** "Starter (10%)" or "Standard (15%)" — how someone's commission plan reads in a sentence. */
    public static function describePlan(?array $policy, ?string $planName, ?float $ownRate): string
    {
        $type = $policy['commission_type'] ?? 'percentage';
        $rate = self::rate($policy, $ownRate);

        return ($planName && $ownRate !== null ? $planName : 'Standard') . ' (' . self::label($rate, $type) . ')';
    }

    /** "Standard (15%)" or "20%" — how someone's commission reads in a sentence (before plans). */
    public static function describe(?array $policy, ?float $ownRate): string
    {
        $type = $policy['commission_type'] ?? 'percentage';

        return $ownRate === null
            ? 'Standard (' . self::label(self::rate($policy), $type) . ')'
            : self::label($ownRate, $type);
    }
}
