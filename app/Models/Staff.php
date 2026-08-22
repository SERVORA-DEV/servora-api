<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'staff';

    // Maps a staff member's job role to the login role their account gets
    // once granted (see AccountService::createAccount) — only these two job
    // roles can ever have a login; therapist never appears here.
    public const ACCOUNT_ROLE_MAP = [
        'manager' => 'manager',
        'frontdesk' => 'front_officer',
    ];

    protected $fillable = [
        'uuid',

        'spa_branch_id',
        'user_id',
        'employee_number',

        'first_name',
        'last_name',
        'middle_name',
        'suffix',

        'email',
        'phone_number',

        'gender',
        'birth_date',
        'hire_date',

        'role',

        'employment_type',
        'status',

        'emergency_contact_name',
        'emergency_contact_number',
        'notes',
    ];

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
        'hire_date' => 'date:Y-m-d',
    ];

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    // Null until this staff member is granted a login (see
    // AccountService::createAccount) — unique(user_id) on the table caps
    // this at one account per staff member.
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
