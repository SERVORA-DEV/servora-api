<?php

namespace App\Support;

use Carbon\CarbonInterface;

// Pricing extra branch / account slots bought in the middle of a term: only
// the days left until the next billing are charged, in whole pesos.
//
//   quantity × price per slot for the cycle × days left ÷ days in the term
//
// e.g. term Jan 1 → Feb 1 (31 days), 2 accounts at ₱100/month bought on
// Jan 15 (17 days left): 2 × 100 × 17 ÷ 31 = ₱109.68 → ₱110. From the next
// billing on, the slots renew at the full price (PlanTerms::renewalAmount).
final class SlotProration
{
    /**
     * [days left, days in the term], counted in calendar days in the app's
     * time zone (Asia/Manila). The day it is bought counts as a day left, so
     * buying on the first day charges the full price and buying on the last
     * day charges one day.
     *
     * @return array{0: int, 1: int}
     */
    public static function days(CarbonInterface $termStart, CarbonInterface $nextBilling, CarbonInterface $now): array
    {
        $tz = (string) config('app.timezone', 'Asia/Manila');
        $start = $termStart->copy()->setTimezone($tz)->startOfDay();
        $due = $nextBilling->copy()->setTimezone($tz)->startOfDay();
        $today = $now->copy()->setTimezone($tz)->startOfDay();

        $period = max(1, (int) round($start->diffInDays($due, false)));
        $left = (int) round($today->diffInDays($due, false));

        return [min($period, max(1, $left)), $period];
    }

    /** The prorated charge in whole pesos (never below ₱1 when anything is bought). */
    public static function amount(float $pricePerCycle, int $quantity, int $daysLeft, int $periodDays): int
    {
        if ($quantity <= 0 || $pricePerCycle <= 0) {
            return 0;
        }

        return max(1, (int) round($pricePerCycle * $quantity * max(0, $daysLeft) / max(1, $periodDays)));
    }
}
