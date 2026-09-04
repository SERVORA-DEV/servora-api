<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpaBranch extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid',
        'spa_business_id',

        'branch_name',

        'email',
        'phone_number',

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

    // Any account (Manager or Front Officer) assigned to a staff member at
    // this branch — reached transitively via Staff.user_id now rather than
    // a branch-owned pivot row (see Staff::user, User::staff).
    public function assignedAccount()
    {
        return $this->hasOneThrough(
            User::class,
            Staff::class,
            'spa_branch_id',
            'id',
            'id',
            'user_id'
        );
    }

    // The branch's Manager specifically — a branch's staff can hold either
    // a Manager or a Front Desk account (see assignedAccount()), so that
    // alone isn't reliable for "who manages this branch"; this filters
    // through to the manager-role staff member's account only.
    public function manager()
    {
        return $this->hasOneThrough(
            User::class,
            Staff::class,
            'spa_branch_id',
            'id',
            'id',
            'user_id'
        )->where('staff.role', 'manager');
    }

    public function branchServices()
    {
        return $this->hasMany(BranchService::class, 'spa_branch_id');
    }

    public function branchPackages()
    {
        return $this->hasMany(BranchPackage::class, 'spa_branch_id');
    }

    public function staff()
    {
        return $this->hasMany(Staff::class, 'spa_branch_id');
    }

    public function facilities()
    {
        return $this->hasMany(Facility::class, 'spa_branch_id');
    }
}
