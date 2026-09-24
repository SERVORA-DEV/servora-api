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

    public const AUDIENCE_WEB = 'web';
    public const AUDIENCE_MOBILE = 'mobile';

    public function uniqueIds()
    {
        return ['uuid'];
    }

    // The same email can back one owner-side ('web') account and one client
    // ('mobile') account, so uniqueness and login lookups are scoped by this.
    // Always derived from role so the two can't drift apart.
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            $user->audience = static::audienceForRole($user->role);
        });
    }

    public static function audienceForRole(?string $role): string
    {
        return $role === 'client' ? self::AUDIENCE_MOBILE : self::AUDIENCE_WEB;
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

        'personal_email',
        'personal_email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'personal_email_otp_hash',
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
            // Unlike the OTP-style secrets elsewhere (hashed, one-way), a TOTP
            // secret must be reversible to generate codes — encrypted-at-rest
            // (via APP_KEY) is the correct protection here, not a hash.
            'two_factor_secret' => 'encrypted',
            'personal_email_verified_at' => 'datetime',
            'personal_email_otp_created_at' => 'datetime',
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

    public function ownerIdentityVerification()
    {
        return $this->hasOne(OwnerIdentityVerification::class);
    }

    // The Staff (employee) record this login belongs to — only meaningful
    // for manager/front_officer accounts (see AccountService). Which branch
    // this account operates at is reached transitively via ->staff->branch
    // (or eager-load 'staff.branch') rather than a column on this table.
    public function staff()
    {
        return $this->hasOne(Staff::class);
    }
}
