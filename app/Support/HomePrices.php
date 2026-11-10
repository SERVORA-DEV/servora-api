<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\BranchPackage;
use App\Models\BranchService;
use App\Models\Package;
use App\Models\ServiceVariant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// What a service length, a package or an add-on costs on a home visit (in place of the flat fee per
// visit, which new bookings no longer get — HomeServiceRules::offer).
//
//   a home visit   its home price when the owner set one, else what it costs at the spa
//   at the spa     always what it costs at the spa (a branch's own price, else the menu's)
//
// A length can also be offered only for home visits (home_only): a spa booking can't take it.
// The price is copied onto the booking when it's made, like every price, so changing it later
// never rewrites a visit. Commission is then worked out from that line (CommissionCalculator), at
// the home service rate when the business uses one.
//
// Before the migration (2026_11_18_090000_add_home_prices) has run there are no home prices:
// everything costs its spa price, and nothing reads or writes the columns.
class HomePrices
{
    public static function ready(): bool
    {
        // Asked often: "yes" is remembered for good, "not yet" for a minute (like Package::hasLinePrice).
        $known = Cache::get('schema:home_prices');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('service_variants', 'home_price')
            && Schema::hasColumn('service_variants', 'home_only')
            && Schema::hasColumn('packages', 'home_price')
            && (! Schema::hasTable('addons') || Schema::hasColumn('addons', 'home_price'));
        $ready ? Cache::forever('schema:home_prices', true) : Cache::put('schema:home_prices', false, 60);

        return $ready;
    }

    /** A home price as stored: a positive amount, or null for "the spa price". */
    public static function clean(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }
        $value = round((float) $value, 2);

        return $value > 0 ? $value : null;
    }

    public static function homeOnly(?ServiceVariant $variant): bool
    {
        return $variant !== null && self::ready() && (bool) $variant->home_only;
    }

    public static function variant(ServiceVariant $variant, ?BranchService $branchService, bool $home): float
    {
        if ($home && self::ready() && $variant->home_price !== null) {
            return (float) $variant->home_price;
        }

        return (float) ($branchService?->custom_price ?? $variant->price);
    }

    public static function package(Package $package, ?BranchPackage $branchPackage, bool $home): float
    {
        if ($home && self::ready() && $package->home_price !== null) {
            return (float) $package->home_price;
        }

        return (float) ($branchPackage?->custom_price ?? $package->default_price);
    }

    public static function addon(Addon $addon, bool $home): float
    {
        if ($home && self::ready() && $addon->home_price !== null) {
            return (float) $addon->home_price;
        }

        return (float) $addon->price;
    }

    /** What a booking says when a spa visit is given a length offered only for home visits. */
    public static function homeOnlyRefusal(ServiceVariant $variant): string
    {
        $name = $variant->service?->name ?? 'This service';

        return "{$name} ({$variant->duration_minutes} min) is only for home visits. Choose another length, or book it as home service.";
    }
}
