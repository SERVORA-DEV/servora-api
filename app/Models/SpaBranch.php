<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpaBranch extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid',
        'spa_business_id',

        'branch_name',

        'email',
        'phone_number',

        'address',

        'city',
        'province',
        'postal_code',

        'latitude',
        'longitude',

        'cover_photo',

        'description',

        'verification_status',
        'operating_status',

        'closure_note',
        'reopens_at',

        'verified_by',
        'verified_at',

        'rejection_reason',
        'suspension_reason',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',

            'reopens_at' => 'date',
            'verified_at' => 'datetime',
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

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function schedules()
    {
        return $this->hasMany(BranchSchedule::class, 'spa_branch_id');
    }

    public function branchServices()
    {
        return $this->hasMany(BranchService::class, 'spa_branch_id');
    }

    public function branchPackages()
    {
        return $this->hasMany(BranchPackage::class, 'spa_branch_id');
    }
}
