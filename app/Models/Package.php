<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'packages';

    protected $fillable = [
        'uuid',
        'spa_business_id',
        'created_by',
        'name',
        'code',
        'description',
        'duration_minutes',
        'default_price',
        'default_commission_amount',
        'loyalty_points',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_price' => 'decimal:2',
            'default_commission_amount' => 'decimal:2',
            'is_active' => 'boolean',
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

    // Ordered by sort_order — PackageServiceItem carries quantity/sort_order,
    // the actual Service comes through its own service() relation.
    public function packageServiceItems()
    {
        return $this->hasMany(PackageServiceItem::class, 'package_id')->orderBy('sort_order');
    }

    public function branchPackages()
    {
        return $this->hasMany(BranchPackage::class, 'package_id');
    }
}
