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
        // Whether the owner lets them be sent to a client's home
        // (HomeServiceRules). Off until turned on.
        'home_service_available',
        // Their own commission rate — the same kind as the standard one in
        // Staff Policies. Null: the standard (App\Support\CommissionShare).
        'commission_rate',
        // The commission plan the owner gave them (App\Models\CommissionPlan);
        // null: the standard rate.
        'commission_plan_id',

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
        'home_service_available' => 'boolean',
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
     * Whether staff can be marked "available for home service" yet (the
     * migration has been run). Until then nobody is held back from a home
     * visit: the setting is neither saved, shown nor enforced.
     */
    public static function hasHomeService(): bool
    {
        $known = Cache::get('schema:staff_home_service');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('staff', 'home_service_available');
        $ready ? Cache::forever('schema:staff_home_service', true) : Cache::put('schema:staff_home_service', false, 60);

        return $ready;
    }

    /**
     * Whether this person may be sent to a client's home: someone who
     * performs services, with the owner's tick on. True for all of them
     * until the setting exists.
     */
    public function takesHomeService(): bool
    {
        if ($this->role !== StaffSpecialty::ROLE) {
            return false;
        }

        return ! self::hasHomeService() || (bool) $this->home_service_available;
    }

    /** Narrows a staff query to those who may be sent to a client's home (everyone until the setting exists). */
    public function scopeForHomeService($query)
    {
        return self::hasHomeService() ? $query->where('home_service_available', true) : $query;
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

    /** Whether staff can be given a commission plan yet (the migration has been run). */
    public static function hasCommissionPlans(): bool
    {
        return CommissionPlan::ready();
    }

    public function commissionPlan()
    {
        return $this->belongsTo(CommissionPlan::class, 'commission_plan_id');
    }

    /**
     * The rate of their commission plan, or null when they earn the standard
     * one. (Before plans existed it was a typed custom rate on the person.)
     */
    public function ownCommissionRate(): ?float
    {
        if ($this->role !== StaffSpecialty::ROLE) {
            return null;
        }
        if (self::hasCommissionPlans()) {
            return $this->commission_plan_id !== null && $this->commissionPlan ? (float) $this->commissionPlan->rate : null;
        }

        return self::hasCustomCommission() && $this->commission_rate !== null ? (float) $this->commission_rate : null;
    }

    /** The name of their commission plan, or null for the standard rate. */
    public function commissionPlanName(): ?string
    {
        return $this->role === StaffSpecialty::ROLE && self::hasCommissionPlans() && $this->commission_plan_id !== null
            ? $this->commissionPlan?->name
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
