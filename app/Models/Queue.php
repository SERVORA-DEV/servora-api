<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// Front-desk queue ticket for an appointment — one-to-one (an appointment
// only ever has one place in line at a time). spa_branch_id/appointment_date
// are denormalized off the appointment at insert time (see
// AppointmentService::addToQueueInternal) so "today's queue for my branch"
// never needs a join.
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
        'queued_at',
        'called_at',
        'served_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date:Y-m-d',
            'queued_at' => 'datetime',
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

    /**
     * Whether queues.queued_at exists yet (the migration has been run). Until
     * then the line is ordered by created_at, as it always was.
     */
    public static function ready(): bool
    {
        $known = Cache::get('schema:queues_queued_at');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('queues', 'queued_at');
        $ready ? Cache::forever('schema:queues_queued_at', true) : Cache::put('schema:queues_queued_at', false, 60);

        return $ready;
    }

    /** The SQL that says where a ticket stands in the line: its place, else when it was made. */
    public static function lineKey(): string
    {
        return self::ready() ? 'COALESCE(queued_at, created_at)' : 'created_at';
    }

    /** This ticket's place in the line, as a time. */
    public function lineTime(): \Illuminate\Support\Carbon
    {
        return (self::ready() ? $this->queued_at : null) ?? $this->created_at;
    }
}
