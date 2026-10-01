<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Billing extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid',

        'subscription_id',
        'appointment_id',

        'spa_business_id',
        'spa_branch_id',

        'billing_type',

        'billing_number',

        // amount = what's owed; subtotal = the pre-discount total it came from.
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'discount_reason',
        'discounted_by',
        // Where the discount came from, when the front desk picked a program
        // or one of the client's vouchers instead of typing it in.
        'discount_program_id',
        'client_voucher_id',

        'amount',
        // Subscription invoices: the 12% VAT included in amount.
        'vat_amount',

        'status',

        'issued_at',
        'due_at',
        'paid_at',

        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',

            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'billing_id');
    }

    public function discountProgram()
    {
        return $this->belongsTo(CustomerProgram::class, 'discount_program_id')->withTrashed();
    }

    public function clientVoucher()
    {
        return $this->belongsTo(ClientVoucher::class, 'client_voucher_id');
    }
}
