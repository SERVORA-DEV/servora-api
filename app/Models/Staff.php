<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\StaffSpecialty;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Staff extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

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
        // What they do (App\Support\StaffSpecialty): a list, the first being
        // their main one. Only for the people who perform services.
        'specialties',
        // Their own commission rate — the same kind as the standard one in
        // Staff Policies. Null: the standard (App\Support\CommissionShare).
        'commission_rate',

        'employment_type',
        'status',

        'emergency_contact_name',
        'emergency_contact_number',
        'notes',
    ];

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
        'hire_date' => 'date:Y-m-d',
        'specialties' => 'array',
    ];

    /**
     * Whether staff have specialties yet (the migration has been run). Until
     * then nobody has one and none is saved or shown.
     */
    public static function hasSpecialties(): bool
    {
        // Asked often, so both answers are remembered: "yes" for good, "not
        // yet" for a minute (same as Service::hasTypes).
        $known = Cache::get('schema:staff_specialties');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('staff', 'specialties');
        $ready ? Cache::forever('schema:staff_specialties', true) : Cache::put('schema:staff_specialties', false, 60);

        return $ready;
    }

    /**
     * Whether a staff member can have a commission of their own yet (the
     * migration has been run). Until then everyone earns the standard rate.
     */
    public static function hasCustomCommission(): bool
    {
        $known = Cache::get('schema:staff_commission');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('staff', 'commission_rate');
        $ready ? Cache::forever('schema:staff_commission', true) : Cache::put('schema:staff_commission', false, 60);

        return $ready;
    }

    /** Their own commission rate, or null when they earn the standard one. */
    public function ownCommissionRate(): ?float
    {
        return self::hasCustomCommission() && $this->role === StaffSpecialty::ROLE && $this->commission_rate !== null
            ? (float) $this->commission_rate
            : null;
    }

    /**
     * What they do, main one first — none for a manager or the front desk,
     * and none until specialties exist.
     *
     * @return string[]
     */
    public function specialtyList(): array
    {
        return self::hasSpecialties() && $this->role === StaffSpecialty::ROLE
            ? StaffSpecialty::clean($this->specialties)
            : [];
    }

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

    public function schedules()
    {
        return $this->hasMany(StaffSchedule::class, 'staff_id');
    }

    // Empty = qualified for every service (opt-in-if-configured) — see
    // AppointmentAvailabilityService::isStaffQualified().
    public function qualifiedServices()
    {
        return $this->belongsToMany(Service::class, 'staff_services', 'staff_id', 'service_id');
    }

    public function therapistAssignments()
    {
        return $this->hasMany(TherapistAssignment::class, 'staff_id');
    }

    public function attendance()
    {
        return $this->hasMany(Attendance::class, 'staff_id');
    }
}
