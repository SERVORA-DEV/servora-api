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
        'formatted_address',

        'cover_photo',

        'permit_document_path',
        'permit_number',
        'permit_business_name',
        'permit_branch_location',
        'permit_issue_date',
        'permit_expiration_date',
        'permit_confirmed',

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

            'permit_issue_date' => 'date',
            'permit_expiration_date' => 'date',
            'permit_confirmed' => 'boolean',
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

    public function accountBranch()
    {
        return $this->hasOne(AccountBranch::class, 'spa_branch_id');
    }

    // The branch's Manager specifically — account_branches can hold either
    // a Manager or a Front Officer assignment (see AccountBranch), so
    // accountBranch() alone isn't reliable for "who manages this branch"
    // when both are assigned; this filters through to the manager-role
    // user only.
    public function manager()
    {
        return $this->hasOneThrough(
            User::class,
            AccountBranch::class,
            'spa_branch_id',
            'id',
            'id',
            'user_id'
        )->where('users.role', 'manager');
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
