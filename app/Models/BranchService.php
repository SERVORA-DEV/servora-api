<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Turns a specific ServiceVariant on for a specific branch, with optional
// per-branch price and commission overrides (custom_price/custom_commission
// null = use the variant's price/commission_amount). custom_commission is
// services-only; branch_packages has no equivalent column.
// Auto-created (is_available=true) for every one of the business's branches
// when the variant itself is created — see ServiceService::createService.
class BranchService extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'spa_branch_id',
        'service_variant_id',
        'custom_price',
        'custom_commission',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'custom_price' => 'decimal:2',
            'custom_commission' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    public function serviceVariant()
    {
        return $this->belongsTo(ServiceVariant::class, 'service_variant_id');
    }
}
