<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Same shape as BranchService, for Package — see that model's docblock.
class BranchPackage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'spa_branch_id',
        'package_id',
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

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id');
    }
}
