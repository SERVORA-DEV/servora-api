<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// A purchase-record only: when a package is added to an appointment it is
// exploded into individual AppointmentService rows (one per package_services
// line x quantity, tagged source_appointment_package_id) so packages get
// full per-service multi-therapist/room support. This row's own `status`
// stays at its Pending default — the exploded services are the single
// source of execution truth, not this row.
class AppointmentPackage extends Model
{
    use HasUuids;

    protected $table = 'appointment_packages';

    protected $fillable = [
        'uuid',
        'appointment_id',
        'package_id',
        // Its own copy of the package's name (App\Support\History).
        'package_name',
        'quantity',
        'unit_price',
        'discount_amount',
        'subtotal',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    // A package removed from the menu is still the package that was sold.
    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id')->withTrashed();
    }

    protected static function booted(): void
    {
        static::saving(function (self $row) {
            if (\App\Support\History::ready() && ($row->isDirty('package_id') || $row->package_name === null)) {
                $row->package_name = Package::withTrashed()->whereKey($row->package_id)->value('name');
            }
        });
    }

    /** The package's name when it was sold — its own copy first. */
    public function displayName(): ?string
    {
        return \App\Support\History::own($this->package_name, fn () => $this->package?->name);
    }

    public function explodedServices()
    {
        return $this->hasMany(AppointmentServiceItem::class, 'source_appointment_package_id');
    }
}
