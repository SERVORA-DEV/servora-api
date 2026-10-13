<?php

namespace App\Support;

// What a package is made of, and what follows from it.
//
// A package names services, not menu lengths: each service in it has the
// minutes the owner gave it *in this package*, and the package has one fixed
// price. The menu's own lengths and prices are only a guide — "separately
// these would cost about ₱…" — and, when the package is booked, each line
// still points at the menu length closest to its minutes, so the rest of the
// visit knows which service it is and what it lists for (the share of the
// package price a voided service takes with it, the price an extension is
// measured from).
//
// Add-ons ticked in a package come with it: their time is part of the
// package's duration and they are not charged on top.
//
// Pure — tested in tests/Unit/PackagePlanTest; mirrored on the site in
// app/utils/packagePlan.ts.
class PackagePlan
{
    /**
     * The menu length closest to these minutes — the shorter one on a tie,
     * the first when no minutes are given. Null when the service has none.
     *
     * @param  list<array{minutes: int|float|string|null}>  $lengths
     * @return ?int  the position in $lengths
     */
    public static function closest(array $lengths, ?int $minutes): ?int
    {
        $best = null;
        foreach ($lengths as $i => $length) {
            $own = (int) ($length['minutes'] ?? 0);
            if ($best === null) {
                $best = $i;
                continue;
            }
            if ($minutes === null || $minutes <= 0) {
                // Nothing to measure against: the shortest.
                if ($own < (int) ($lengths[$best]['minutes'] ?? 0)) {
                    $best = $i;
                }
                continue;
            }
            $gap = abs($own - $minutes);
            $bestGap = abs((int) ($lengths[$best]['minutes'] ?? 0) - $minutes);
            if ($gap < $bestGap || ($gap === $bestGap && $own < (int) ($lengths[$best]['minutes'] ?? 0))) {
                $best = $i;
            }
        }

        return $best;
    }

    /**
     * The package's whole duration: each service's minutes for every one of
     * it, plus what the included add-ons add.
     *
     * @param  list<array{minutes: int|float|string|null, quantity?: int|float|string|null}>  $lines
     * @param  array<int, int|float|string|null>  $addonMinutes
     */
    public static function totalMinutes(array $lines, array $addonMinutes = []): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += max(0, (int) ($line['minutes'] ?? 0)) * max(1, (int) ($line['quantity'] ?? 1));
        }
        foreach ($addonMinutes as $minutes) {
            $total += max(0, (int) $minutes);
        }

        return $total;
    }

    /**
     * What the same things would cost bought separately — a guide, from the
     * menu price nearest each service's minutes.
     *
     * @param  list<array{price: int|float|string|null, quantity?: int|float|string|null}>  $lines
     * @param  array<int, int|float|string|null>  $addonPrices
     */
    public static function estimate(array $lines, array $addonPrices = []): float
    {
        $total = 0.0;
        foreach ($lines as $line) {
            $total += max(0, (float) ($line['price'] ?? 0)) * max(1, (int) ($line['quantity'] ?? 1));
        }
        foreach ($addonPrices as $price) {
            $total += max(0, (float) $price);
        }

        return round($total, 2);
    }
}
