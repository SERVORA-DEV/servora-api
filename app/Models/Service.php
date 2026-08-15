<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'services';

    protected $fillable = [
        'uuid',
        'spa_business_id',
        'created_by',
        'name',
        'description',
        'duration_minutes',
        'default_price',
        'default_commission_percentage',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_price' => 'decimal:2',
            'default_commission_percentage' => 'decimal:2',
            'is_default' => 'boolean',
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

    public function branchServices()
    {
        return $this->hasMany(BranchService::class, 'service_id');
    }
}
