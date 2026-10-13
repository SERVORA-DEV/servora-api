<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// One customer program a business runs:
//   loyalty    — the single points program. values: earnPerPesos + earnPoints = "for every ₱10
//                paid, 1 point" (two whole numbers, so nobody types 0.1); redeemPerVisit = the most
//                vouchers a client can get with points in one visit (empty = no limit);
//                expiryMonths. pointsPerPeso (points per ₱1) is kept alongside, worked out from the
//                two earn numbers on save, and is all an older row has. Points have no peso value
//                of their own and there is no minimum to redeem: what points buy is set by each
//                voucher's points price. Older rows may still carry pointValue, pointsForOnePeso
//                and minRedeem; nothing reads them.
//   voucher    — a deal (values: dealType, value, usableOn, validDays) plus the
//                condition a client earns it by (condition_kind/condition_value). It can be for
//                certain services only — usableKind/usableCategory/usableServices say what it
//                comes off, earnKind/earnCategory/earnServices which visits count toward an
//                "after N visits" one (see App\Support\VoucherTarget); usableOn and earnOn are
//                the readable forms, written on save
//   discount   — for every client, or members/promos only (audience; values: kind, percent, appliesTo, eligible)
//   membership — a paid bundle of vouchers + discounts (values: price, billing, perks; items())
// `values` keeps the same keys the web forms use.
class CustomerProgram extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPES = ['loyalty', 'voucher', 'discount', 'membership'];
    public const AUDIENCES = ['all', 'members'];
    public const CONDITIONS = ['min_spend', 'visits', 'points', 'first_visit', 'birthday', 'membership_only'];

    protected $fillable = [
        'uuid',
        'spa_business_id',
        'type',
        'name',
        'is_active',
        'audience',
        'values',
        'condition_kind',
        'condition_value',
        // The branch this program was made for, when a manager asked for it
        // (ProgramRequestService): only that branch's manager can switch it
        // on. Null for the owner's own programs, and again once the owner
        // offers it at another branch.
        'only_branch_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'values' => 'array',
            'condition_value' => 'integer',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }

    public function branches()
    {
        return $this->belongsToMany(SpaBranch::class, 'customer_program_branch', 'customer_program_id', 'spa_branch_id')->withTimestamps();
    }

    public function onlyBranch()
    {
        return $this->belongsTo(SpaBranch::class, 'only_branch_id');
    }

    // A membership's bundle: the vouchers and discounts members get.
    public function items()
    {
        return $this->belongsToMany(CustomerProgram::class, 'customer_program_items', 'membership_program_id', 'item_program_id')->withTimestamps();
    }

    public function value(string $key, $default = null)
    {
        $v = $this->values[$key] ?? null;

        return $v === null || $v === '' ? $default : $v;
    }

    public function offeredAt(?int $branchId): bool
    {
        if (! $branchId) {
            return false;
        }

        return $this->relationLoaded('branches')
            ? $this->branches->contains('id', $branchId)
            : $this->branches()->where('spa_branches.id', $branchId)->exists();
    }
}
