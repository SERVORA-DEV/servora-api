<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// One bookable duration/price/points combo under a parent
// Service (e.g. "30 min / ₱450" vs "60 min / ₱900" under "Swedish
// Massage"). branch_services and package_services point here, not at
// services directly, since price/points live at this level. Commission is
// never set per item — it comes from Business Settings → Staff Policies.
class ServiceVariant extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid',
        'service_id',
        'duration_minutes',
        'price',
        // Home service (App\Support\HomePrices): its price at the client's home (null = the
        // price above) and whether it is offered only for home visits.
        'home_price',
        'home_only',
        'loyalty_points',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'home_price' => 'decimal:2',
            'home_only' => 'boolean',
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
