<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Which staff member (and which room) is handling one booked service. One
// appointment_service can have many of these — this is what makes
// multi-therapist-per-service possible. facility_id is nullable since a
// therapist is typically assigned before a room is picked.
class TherapistAssignment extends Model
{
    use HasUuids;

    protected $table = 'therapist_assignments';

    protected $fillable = [
        'uuid',
        'appointment_service_id',
        'staff_id',
        'facility_id',
        'assigned_at',
        'started_at',
        'completed_at',
        'assignment_status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function appointmentService()
    {
        return $this->belongsTo(AppointmentServiceItem::class, 'appointment_service_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
