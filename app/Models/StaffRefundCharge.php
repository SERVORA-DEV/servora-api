<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// One staff member's part of a refund, under a policy that charges staff
// (App\Support\RefundCharge). Comes off their earnings for the day it was
// refunded, like a penalty — see App\Service\Business\StaffRefundChargeReader.
class StaffRefundCharge extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'spa_business_id',
        'spa_branch_id',
        'billing_adjustment_id',
        'billing_id',
        'staff_id',
        'amount',
        'charged_on',
        'staff_name',
        'billing_number',
        'policy_name',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'charged_on' => 'date',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    /** Whether the table and the policy columns exist yet (the migration has been run). */
    public static function ready(): bool
    {
        $known = Cache::get('schema:refund_charges');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasTable('staff_refund_charges') && Schema::hasColumn('refund_policies', 'charge_to');
        $ready ? Cache::forever('schema:refund_charges', true) : Cache::put('schema:refund_charges', false, 60);

        return $ready;
    }
}
