<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'appointments';

    protected $fillable = [
        'uuid',
        'spa_branch_id',
        'client_id',
        'appointment_number',
        'appointment_date',
        'appointment_time',
        'appointment_type',
        'source',
        'status',
        'check_in_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'remarks',
        'subtotal',
        'discount_amount',
        'total_amount',
        'reward_redemption_id',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date:Y-m-d',
            'check_in_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function services()
    {
        return $this->hasMany(AppointmentServiceItem::class, 'appointment_id')->orderBy('sort_order');
    }

    public function packages()
    {
        return $this->hasMany(AppointmentPackage::class, 'appointment_id');
    }

    public function queue()
    {
        return $this->hasOne(Queue::class, 'appointment_id');
    }

    // Loose-in-spirit but FK'd (billings.appointment_id -> appointments.id) —
    // one billing per appointment, created once by
    // AppointmentService::proceedToBilling().
    public function billing()
    {
        return $this->hasOne(Billing::class, 'appointment_id');
    }
}
