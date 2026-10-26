<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// A change to a bill after it was paid — a voucher put on afterwards or a
// refund — applied at once or waiting for approval. See
// App\Service\Business\BillingAdjustmentService.
class BillingAdjustment extends Model
{
    use HasUuids;

    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const DECLINED = 'declined';
    public const WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'uuid',
        'spa_business_id',
        'spa_branch_id',
        'billing_id',
        'kind',
        'amount',
        'client_voucher_id',
        'voucher_name',
        'reason',
        'status',
        'needs',
        'requested_by_user_id',
        'requested_by_name',
        'requested_by_role',
        'decided_by_user_id',
        'decided_by_name',
        'decided_at',
        'decision_note',
        // The refund policy it was given under, with its own copy of it
        // (columns from the refund_policies migration; RefundPolicy::ready).
        'refund_policy_id',
        'refund_policy_name',
        'refund_policy_rule',
        // Who it comes out of, as the policy said (App\Support\RefundCharge),
        // and what came off staff earnings (StaffRefundCharge::ready).
        'charge_to',
        'staff_share',
        'charge_rule',
        'staff_charged',
        // Before payment: the service it was for (empty: the whole bill) and
        // its name as it was (BillingAdjustment::creditsReady).
        'appointment_service_id',
        'service_name',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'decided_at' => 'datetime',
            'staff_share' => 'decimal:2',
            'staff_charged' => 'decimal:2',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function billing()
    {
        return $this->belongsTo(Billing::class, 'billing_id');
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function voucher()
    {
        return $this->belongsTo(ClientVoucher::class, 'client_voucher_id');
    }

    /** Before payment: the service it was for (BillingAdjustment::creditsReady). */
    public function service()
    {
        return $this->belongsTo(AppointmentServiceItem::class, 'appointment_service_id');
    }

    /** The parts of it charged to staff (StaffRefundCharge::ready). */
    public function charges()
    {
        return $this->hasMany(StaffRefundCharge::class, 'billing_adjustment_id');
    }

    /** Whether refunds before payment can be kept yet (the migration has been run). */
    public static function creditsReady(): bool
    {
        $known = Cache::get('schema:refunds_before_payment');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('billings', 'credit_amount') && Schema::hasColumn('billing_adjustments', 'appointment_service_id');
        $ready ? Cache::forever('schema:refunds_before_payment', true) : Cache::put('schema:refunds_before_payment', false, 60);

        return $ready;
    }

    /** Whether the table and user_permissions.payment_adjust exist yet (the migration has been run). */
    public static function ready(): bool
    {
        $known = Cache::get('schema:billing_adjustments');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasTable('billing_adjustments') && Schema::hasColumn('user_permissions', 'payment_adjust');
        $ready ? Cache::forever('schema:billing_adjustments', true) : Cache::put('schema:billing_adjustments', false, 60);

        return $ready;
    }
}
