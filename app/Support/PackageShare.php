<?php

namespace App\Support;

// A package is one price for several services. When one of those services is
// voided, this is how much of the package price goes with it: its share by
// list price. A ₱520 package of two ₱300 services gives ₱260 back for either;
// with a ₱300 and a ₱100 service it gives ₱390 or ₱130.
//
// Worked out over the services still on the package and what the package is
// still charged, so voiding them one after another always ends at exactly
// zero — the last one takes whatever is left.
//
// Pure — tested in tests/Unit/PackageShareTest.
// AppointmentService::voidService applies it.
class PackageShare
{
    /**
     * @param  float  $packagePrice  what the package is charged now
     * @param  array<int|string, float>  $listPrices  each service still on the package => its list price
     * @param  int|string  $line  the service being taken off
     */
    public static function of(float $packagePrice, array $listPrices, int|string $line): float
    {
        if (! array_key_exists($line, $listPrices)) {
            return 0.0;
        }
        if (count($listPrices) === 1) {
            return round(max(0, $packagePrice), 2);
        }

        $prices = array_map(fn ($price) => max(0.0, (float) $price), $listPrices);
        $total = array_sum($prices);

        // Nothing to weigh by: equal parts.
        $share = $total > 0 ? $packagePrice * $prices[$line] / $total : $packagePrice / count($prices);

        return round(max(0, min($packagePrice, $share)), 2);
    }
}
