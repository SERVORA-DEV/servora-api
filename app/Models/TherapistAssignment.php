<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Which staff member (and which room) is handling one booked service. One
// appointment_service can have many of these — this is what makes
// multi-therapist-per-service possible. facility_id is nullable since a
// therapist is typically assigned before a room is picked, and staff_id is
// nullable too — a room can be reserved for a service before any therapist
// is picked ("room-only" row); see
// TherapistAssignmentRepository::assignRoomOnly()/claim().
class TherapistAssignment extends Model
{
    use HasUuids;

    protected $table = 'therapist_assignments';

    protected $fillable = [
        'uuid',
        'appointment_service_id',
        'staff_id',
        'facility_id',
        // Their names at the time, and what the therapist earned for it when
        // it was completed (App\Support\History).
        'staff_name',
        'facility_name',
        'commission_amount',
        // The rate that amount was worked out at, and its kind
        // ('percentage' | 'fixed') — kept with it.
        'commission_rate',
        'commission_type',
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

    protected static function booted(): void
    {
        // The names are copied when someone is put on the service, and again
        // only when that someone (or the room) is changed.
        static::saving(function (self $row) {
            if (! \App\Support\History::ready()) {
                return;
            }
            if ($row->isDirty('staff_id') || ($row->staff_id && $row->staff_name === null)) {
                $staff = $row->staff_id ? Staff::withTrashed()->find($row->staff_id, ['id', 'first_name', 'last_name']) : null;
                $row->staff_name = \App\Support\History::person($staff?->first_name, $staff?->last_name);
            }
            if ($row->isDirty('facility_id') || ($row->facility_id && $row->facility_name === null)) {
                $row->facility_name = $row->facility_id ? Facility::withTrashed()->whereKey($row->facility_id)->value('name') : null;
            }
        });
    }

    /** The therapist's name when they were put on it — its own copy first. */
    public function staffName(): ?string
    {
        return \App\Support\History::own($this->staff_name, fn () => $this->staff
            ? \App\Support\History::person($this->staff->first_name, $this->staff->last_name)
            : null);
    }

    /** The room's name at the time — its own copy first. */
    public function roomName(): ?string
    {
        return \App\Support\History::own($this->facility_name, fn () => $this->facility?->name);
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
