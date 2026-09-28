<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid',

        'billing_id',

        'spa_business_id',
        'spa_branch_id',

        'payment_method',

        'gateway_provider',
        'gateway_reference',

        'reference_number',

        'amount',
        // Cash only: what the client handed over, and the change given back.
        'amount_tendered',
        'change_given',

        'payment_status',

        'paid_at',

        'refunded_amount',
        'refund_reason',

        // A payment entered by mistake (payment_status 'Voided').
        'voided_at',
        'voided_by',
        // The staff member who took the payment.
        'received_by',

        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_tendered' => 'decimal:2',
            'change_given' => 'decimal:2',
            'refunded_amount' => 'decimal:2',

            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function billing()
    {
        return $this->belongsTo(Billing::class, 'billing_id');
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }
}
