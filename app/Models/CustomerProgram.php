<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// One customer program a business runs:
//   loyalty    — the single points program (values: pointsPerPeso, pointValue, minRedeem, expiryMonths)
//   voucher    — a deal (values: dealType, value, usableOn, validDays) plus the
//                condition a client earns it by (condition_kind/condition_value)
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
