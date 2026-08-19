<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// One bookable duration/price/commission/points combo under a parent
// Service (e.g. "30 min / ₱450" vs "60 min / ₱900" under "Swedish
// Massage"). branch_services and package_services point here, not at
// services directly, since price/commission/points live at this level.
class ServiceVariant extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid',
        'service_id',
        'duration_minutes',
        'price',
        'commission_amount',
        'loyalty_points',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function branchServices()
    {
        return $this->hasMany(BranchService::class, 'service_variant_id');
    }

    public function packageServiceItems()
    {
        return $this->hasMany(PackageServiceItem::class, 'service_variant_id');
    }
}
