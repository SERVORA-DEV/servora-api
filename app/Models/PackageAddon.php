<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// An add-on that comes with a package (App\Support\PackagePlan): which
// add-on, and which of the package's services it goes on. Included in the
// package's price — when the package is booked it becomes an add-on line
// under that service, not charged on top.
class PackageAddon extends Model
{
    protected $table = 'package_addons';

    protected $fillable = [
        'package_id',
        'addon_id',
        'service_id',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    // Kept even after the owner removes the add-on, so the package still
    // reads; a removed or switched-off one is simply left out of a booking.
    public function addon()
    {
        return $this->belongsTo(Addon::class, 'addon_id')->withTrashed();
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /** Still on the menu and switched on: it goes onto a new booking. */
    public function offered(): bool
    {
        return $this->addon !== null && ! $this->addon->trashed() && (bool) $this->addon->is_active;
    }
}
