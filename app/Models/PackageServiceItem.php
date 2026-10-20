<?php

namespace App\Models;

use App\Support\PackagePlan;
use Illuminate\Database\Eloquent\Model;

// Maps to the package_services table — named ...Item (not PackageService) to
// avoid colliding with App\Service\Business\PackageService, the business
// logic class for the packages resource itself.
//
// One service in a package (App\Support\PackagePlan): which service, how long
// it runs in this package, and how many. A row saved before packages named
// services still pins a menu length (service_variant_id) and reads through
// it — theService() / minutes() / guideVariant() answer for both.
class PackageServiceItem extends Model
{
    protected $table = 'package_services';

    protected $fillable = [
        'package_id',
        'service_id',
        'duration_minutes',
        'service_variant_id',
        'quantity',
        // What this service is worth in the package, per one (null: the menu's
        // price for its nearest length — see unitPrice()).
        'price',
        'sort_order',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function serviceVariant()
    {
        return $this->belongsTo(ServiceVariant::class, 'service_variant_id');
    }

    /** The service this row is for. */
    public function theService(): ?Service
    {
        return $this->service_id ? $this->service : $this->serviceVariant?->service;
    }

    /** How long it runs in this package. */
    public function minutes(): ?int
    {
        $minutes = $this->duration_minutes ?? $this->serviceVariant?->duration_minutes;

        return $minutes !== null ? (int) $minutes : null;
    }

    /**
     * What one of this service is worth in the package: the price the owner
     * gave it, else the menu's price for the length nearest its minutes.
     */
    public function unitPrice(): float
    {
        if (Package::hasLinePrice() && $this->price !== null) {
            return (float) $this->price;
        }

        return (float) ($this->guideVariant()?->price ?? 0);
    }

    /**
     * The menu length nearest its minutes: what a booked line points at, and
     * the price it lists for. The pinned length for an older row.
     */
    public function guideVariant(): ?ServiceVariant
    {
        if (! $this->service_id) {
            return $this->serviceVariant;
        }

        $variants = $this->service?->variants?->values() ?? collect();
        $at = PackagePlan::closest($variants->map(fn ($v) => ['minutes' => $v->duration_minutes])->all(), $this->minutes());

        return $at === null ? null : $variants[$at];
    }
}
