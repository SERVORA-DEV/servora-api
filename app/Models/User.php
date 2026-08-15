<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\HasApiTokens;   
use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    public function uniqueIds()
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'role',

        'username',

        'first_name',
        'middle_name',
        'last_name',
        'suffix',

        'gender',
        'birth_date',

        'phone_number',
        'email',

        'password',

        'profile_photo',

        'email_verified_at',
        'phone_verified_at',

        'account_status',
        'onboarding_completed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function permission()
    {
        return $this->hasOne(UserPermission::class);
    }

    public function business()
    {
        return $this->hasOne(SpaBusiness::class, 'owner_id');
    }

    // Which branch this account operates at — only meaningful for
    // manager/front_officer accounts (see AccountService). Goes through
    // account_branches rather than a column on this table; use
    // ->accountBranch->branch to reach the actual SpaBranch (or eager-load
    // 'accountBranch.branch').
    public function accountBranch()
    {
        return $this->hasOne(AccountBranch::class);
    }
}
