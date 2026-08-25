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

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function explodedServices()
    {
        return $this->hasMany(AppointmentServiceItem::class, 'source_appointment_package_id');
    }
}
