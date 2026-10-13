<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use App\Support\ClientProfile;

class Client extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'clients';

    // Simple operational attributes only — a walk-in with no account never
    // needs more than this to be identified/contacted. Full profile detail
    // (middle name, suffix, gender, birth date, avatar) lives on `users` for
    // anyone who has a Servora account and is read through user(), not
    // duplicated here — see ClientResource's `account` block.
    //
    // Two things are the spa's own to record, account or not: the client's
    // gender and their age (kept as a birth year) — App\Support\ClientProfile.
    protected $fillable = [
        'uuid',
        'spa_business_id',
        'spa_branch_id',
        'user_id',
        'preferred_staff_id',
        'first_name',
        'last_name',
        'gender',
        'birth_year',
        'phone_number',
        'email',
        'current_points',
        'lifetime_points',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'current_points' => 'integer',
            'lifetime_points' => 'integer',
            'birth_year' => 'integer',
        ];
    }

    /**
     * Whether clients have a gender and an age yet (the migration has been
     * run). Until then neither is saved, shown or asked for.
     */
    public static function hasProfile(): bool
    {
        // Asked often, so both answers are remembered: "yes" for good, "not
        // yet" for a minute (same as Service::hasTypes).
        $known = Cache::get('schema:client_profile');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('clients', 'gender');
        $ready ? Cache::forever('schema:client_profile', true) : Cache::put('schema:client_profile', false, 60);

        return $ready;
    }

    /**
     * Whether this client uses the Servora app: their record here is linked
     * to an app account (made the first time they book from the app). The
     * front desk sees it as the "App user" label, and only an app user earns
     * loyalty points (App\Support\LoyaltyEarning).
     */
    public function usesApp(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * 'Male' | 'Female' | null: what the spa recorded, or else what the
     * client's own account says.
     */
    public function genderValue(): ?string
    {
        $own = self::hasProfile() ? ClientProfile::gender($this->gender) : null;

        return $own ?? ($this->user_id ? ClientProfile::gender($this->user?->gender) : null);
    }

    /** Their age this year: from the birth year recorded, or else their account's birth date. */
    public function ageValue(): ?int
    {
        $year = self::hasProfile() ? $this->birth_year : null;
        if ($year === null && $this->user_id && filled($this->user?->birth_date)) {
            $year = \Illuminate\Support\Carbon::parse($this->user->birth_date)->year;
        }

        return ClientProfile::age($year !== null ? (int) $year : null, (int) now()->year);
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // The therapist picked for this client by hand (a manager or the front
    // desk). When there is none, the preference is worked out from who their
    // bookings asked for most (ClientPreferenceService, App\Support\
    // FrequentTherapist) — nothing is stored for that. Single therapist, not
    // a favorites list.
    public function preferredTherapist()
    {
        return $this->belongsTo(Staff::class, 'preferred_staff_id');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'client_id');
    }

    // Customer programs — see App\Service\Business\ProgramRewardService.
    public function memberships()
    {
        return $this->hasMany(ClientMembership::class);
    }

    // The membership running right now, if any.
    public function activeMembership()
    {
        return $this->hasOne(ClientMembership::class)->ofMany(
            ['ends_at' => 'max', 'id' => 'max'],
            fn ($q) => $q->where('status', 'active')->where('ends_at', '>', now()),
        );
    }

    public function vouchers()
    {
        return $this->hasMany(ClientVoucher::class);
    }

    public function pointEntries()
    {
        return $this->hasMany(ClientPointEntry::class);
    }
}
