<?php

namespace App\Models;

use App\Support\RefundCharge;
use App\Support\RefundPolicyRule;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// One of the owner's refund policies — see App\Support\RefundPolicyRule and
// App\Service\Business\RefundPolicyService. Removing one keeps it (soft
// delete) so refunds given under it still read as they were; they also keep
// their own copy of its name and rule.
class RefundPolicy extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid',
        'spa_business_id',
        'name',
        'description',
        'mode',
        'value',
        'allow_less',
        'needs_approval',
        'sort_order',
        // Who it comes out of (App\Support\RefundCharge); columns from the
        // refund-charges migration (StaffRefundCharge::ready).
        'charge_to',
        'staff_share',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'allow_less' => 'boolean',
            'needs_approval' => 'boolean',
            'staff_share' => 'decimal:2',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function rule(): string
    {
        return RefundPolicyRule::describe($this->mode, $this->value !== null ? (float) $this->value : null, (bool) $this->allow_less);
    }

    public function chargeTo(): string
    {
        return in_array($this->charge_to, RefundCharge::WHO, true) ? $this->charge_to : RefundCharge::BUSINESS;
    }

    public function chargeRule(): string
    {
        return RefundCharge::describe($this->chargeTo(), $this->staff_share !== null ? (float) $this->staff_share : null);
    }

    /** Whether the table and the billing_adjustments columns exist yet (the migration has been run). */
    public static function ready(): bool
    {
        $known = Cache::get('schema:refund_policies');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasTable('refund_policies') && Schema::hasColumn('billing_adjustments', 'refund_policy_id');
        $ready ? Cache::forever('schema:refund_policies', true) : Cache::put('schema:refund_policies', false, 60);

        return $ready;
    }
}
