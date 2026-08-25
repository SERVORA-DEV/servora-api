<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Front-desk queue ticket for an appointment — one-to-one (an appointment
// only ever has one place in line at a time). spa_branch_id/appointment_date
// are denormalized off the appointment at insert time (see
// AppointmentService::addToQueue) so "today's queue for my branch" never
// needs a join.
class Queue extends Model
{
    use HasUuids;

    protected $table = 'queues';

    protected $fillable = [
        'uuid',
        'appointment_id',
        'spa_branch_id',
        'appointment_date',
        'queue_number',
        'queue_status',
        'called_at',
        'served_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date:Y-m-d',
            'called_at' => 'datetime',
            'served_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }
}
