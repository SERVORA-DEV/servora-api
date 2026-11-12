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
        'home_duration_minutes',
        'home_price',
        'service_variant_id',
        'quantity',
        // What this service is worth in the package, per one (null: the menu's
        // price for its nearest length — see unitPrice()).
        'price',
        'sort_order',
        // A package for several clients: which of them get this service ([1], [2], [1,2]).
        // Null: every client.
        'client_slots',
    ];

    protected function casts(): array
    {
        return ['client_slots' => 'array'];
    }

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    /** Whether the client in this slot (1 = Client A) gets this service. */
    public function isFor(int $slot): bool
    {
        $slots = Package::hasSharing() ? $this->client_slots : null;

        return $slots === null || $slots === [] || in_array($slot, array_map('intval', $slots), true);
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
    public function minutes(bool $home = false): ?int
    {
        $minutes = $home ? $this->home_duration_minutes : ($this->duration_minutes ?? $this->serviceVariant?->duration_minutes);

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
    public function guideVariant(bool $home = false): ?ServiceVariant
    {
        if (! $this->service_id && ! $home) {
            return $this->serviceVariant;
        }

        $variants = ($this->theService()?->variants ?? collect())->filter(fn ($v) => ($v->service_location ?? 'In Spa') === ($home ? 'Home Service' : 'In Spa'))->values();
        $at = PackagePlan::closest($variants->map(fn ($v) => ['minutes' => $v->duration_minutes])->all(), $this->minutes($home));

        return $at === null ? null : $variants[$at];
    }
}
