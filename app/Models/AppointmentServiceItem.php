<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Maps to the appointment_services table — named ...Item (not
// AppointmentService) to avoid colliding with
// App\Service\Business\AppointmentService, the business logic/orchestrator
// class for the appointments resource itself (same reasoning as
// PackageServiceItem vs. App\Service\Business\PackageService).
//
// A single service booked within an appointment. Multi-therapist support
// hangs off this row (therapistAssignments), not off Appointment directly —
// one appointment can have many of these, each independently assigned and
// progressed. Never deleted once created (cancelled instead) to preserve
// history — see AppointmentServiceRepository::cancel().
class AppointmentServiceItem extends Model
{
    use HasUuids;

    protected $table = 'appointment_services';

    protected $fillable = [
        'uuid',
        'appointment_id',
        'source_appointment_package_id',
        'service_variant_id',
        'quantity',
        'sort_order',
        'unit_price',
        'discount_amount',
        'subtotal',
        'points_earned',
        'status',
        'notes',
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

    public function serviceVariant()
    {
        return $this->belongsTo(ServiceVariant::class, 'service_variant_id');
    }

    public function sourceAppointmentPackage()
    {
        return $this->belongsTo(AppointmentPackage::class, 'source_appointment_package_id');
    }

    public function therapistAssignments()
    {
        return $this->hasMany(TherapistAssignment::class, 'appointment_service_id');
    }
}
