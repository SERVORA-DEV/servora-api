<?php

namespace App\Support;

// Who a refund comes out of, as the refund policy says (charge_to):
//   business  the spa bears it; nobody's earnings change;
//   staff     all of it comes off the earnings of whoever did the visit;
//   both      split equally: half off their earnings, half the spa bears
//             (staff_share is kept as 50, so another split can come later).
//
// Chosen per refund policy, and by the person giving a refund outside the
// policies (BillingAdjustmentService).
//
// The staff part is shared between the people who did the visit by what each
// did (weights: the service's charge on the bill, shared equally between the
// people who did that service). A visit nobody is recorded as doing leaves it
// all with the business. Each person's part then comes off their earnings for
// the pay period it was refunded in, like a late or absent penalty — never
// below ₱0 (StaffEarningsReport, AttendancePenalty::settle).
//
// Pure — tested in tests/Unit/RefundChargeTest.
class RefundCharge
{
    public const BUSINESS = 'business';
    public const STAFF = 'staff';
    public const BOTH = 'both';
    public const WHO = [self::BUSINESS, self::STAFF, self::BOTH];

    // "Equal": the staff's share when it is split.
    public const EQUAL_SHARE = 50.0;

    /** The staff share to keep for a choice: 100, 50 (equal) or 0. */
    public static function shareFor(string $chargeTo): ?float
    {
        return $chargeTo === self::BOTH ? self::EQUAL_SHARE : null;
    }

    /**
     * @param  array<int, float>  $weights  staff id => what they did on the visit
     * @return array{business: float, staff: array<int, float>}  the business's part, and each person's
     */
    public static function split(float $refund, string $chargeTo, ?float $staffShare, array $weights): array
    {
        $refund = round(max(0, $refund), 2);
        $weights = array_filter($weights, fn ($w) => $w > 0);
        $share = match ($chargeTo) {
            self::STAFF => 100.0,
            self::BOTH => min(max((float) $staffShare, 0), 100),
            default => 0.0,
        };
        $staffTotal = $weights ? round($refund * $share / 100, 2) : 0.0;
        if ($staffTotal <= 0) {
            return ['business' => $refund, 'staff' => []];
        }

        // In centavos, so the parts add up to exactly the total. Each part is
        // rounded down, and the centavos that leaves over go one each to
        // whoever was closest to the next centavo (then to whoever did more).
        $cents = (int) round($staffTotal * 100);
        $sum = array_sum($weights);
        $parts = [];
        $over = [];
        foreach ($weights as $id => $w) {
            $exact = $cents * $w / $sum;
            $parts[$id] = (int) floor($exact);
            $over[$id] = [$exact - $parts[$id], $w];
        }
        uasort($over, fn ($x, $y) => [$y[0], $y[1]] <=> [$x[0], $x[1]]);
        foreach (array_slice(array_keys($over), 0, $cents - array_sum($parts)) as $id) {
            $parts[$id]++;
        }

        return [
            'business' => round($refund - $staffTotal, 2),
            'staff' => array_map(fn (int $c) => round($c / 100, 2), array_filter($parts, fn ($c) => $c > 0)),
        ];
    }

    /** "The business pays", "Staff pay all of it", "Staff pay 50%, the business the rest". */
    public static function describe(string $chargeTo, ?float $staffShare): string
    {
        return match ($chargeTo) {
            self::STAFF => 'Staff pay all of it',
            self::BOTH => (float) ($staffShare ?? self::EQUAL_SHARE) === self::EQUAL_SHARE
                ? 'Split equally between staff and the business'
                : 'Staff pay ' . rtrim(rtrim(number_format((float) $staffShare, 2), '0'), '.') . '%, the business the rest',
            default => 'The business pays',
        };
    }

    /** Why it can't be saved, or null. */
    public static function invalid(string $chargeTo): ?string
    {
        return in_array($chargeTo, self::WHO, true) ? null : 'Pick who the refund comes out of.';
    }
}
