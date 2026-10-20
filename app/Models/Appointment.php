<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Appointment extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'appointments';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_IN_SERVICE = 'in_service';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_CHECKED_IN,
        self::STATUS_IN_SERVICE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    // Single source of truth for "what can this appointment become next" —
    // mirrors the required workflow exactly: Scheduled -> Checked In -> In
    // Service -> Completed, with Cancelled/No Show as the only alternate
    // branches off Scheduled (Cancelled also reachable from Checked In, so
    // a client who leaves before service starts can still be cancelled).
    // Terminal states have no outgoing transitions. No client-confirmation
    // status exists in this workflow at all.
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_SCHEDULED => [self::STATUS_CHECKED_IN, self::STATUS_CANCELLED, self::STATUS_NO_SHOW],
        self::STATUS_CHECKED_IN => [self::STATUS_IN_SERVICE, self::STATUS_CANCELLED],
        self::STATUS_IN_SERVICE => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_NO_SHOW => [],
    ];

    public function canTransitionTo(string $next): bool
    {
        return in_array($next, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    // True once every service on the visit is done (Completed or
    // Cancelled) — the appointment's own `status` column stays 'in_service'
    // through this point and only becomes STATUS_COMPLETED after payment
    // (see AppointmentService::recordPayment()), so this is the actual
    // signal for "nothing left to do here but bill it." Mirrors the
    // frontend's own `allServicesResolved` computed
    // (app/pages/frontoffice/appointments/[uuid].vue) exactly — both sides
    // must agree on this or the UI and the API guards it enforces drift.
    public function allServicesResolved(): bool
    {
        return $this->services->isNotEmpty()
            && $this->services->every(fn (AppointmentServiceItem $s) => in_array($s->status, ['Completed', 'Cancelled'], true));
    }

    protected $fillable = [
        'uuid',
        'spa_branch_id',
        'client_id',
        'appointment_number',
        'appointment_date',
        'appointment_time',
        'appointment_type',
        // Home service (HomeServiceRules): where the visit happens, the
        // client's pinned address, and the flat fee added to the total.
        'service_location',
        'home_address',
        'home_address_notes',
        'home_latitude',
        'home_longitude',
        'home_distance_km',
        'home_service_fee',
        // Starting a home visit by scanning (App\Support\HomeVisitStart):
        // the code in the client's QR, and when a scan started the visit.
        'home_start_code',
        'home_scanned_at',
        // 'Male' | 'Female' | null — App\Support\StaffGender.
        'preferred_staff_gender',
        'source',
        'status',
        'check_in_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'remarks',
        // The voucher the client booked with (Appointment::holdsVouchers).
        'client_voucher_id',
        // Booking rules someone went ahead despite, each with its reason
        // (App\Service\Business\PolicyGate).
        'policy_overrides',
        'subtotal',
        'discount_amount',
        'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date:Y-m-d',
            'policy_overrides' => 'array',
            'check_in_at' => 'datetime',
            'home_scanned_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'home_latitude' => 'float',
            'home_longitude' => 'float',
            'home_distance_km' => 'float',
            'home_service_fee' => 'decimal:2',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function isHomeService(): bool
    {
        return $this->service_location === 'Home Service';
    }

    /**
     * Whether home visits have start codes and bookings keep a preferred
     * staff gender yet (the migration has been run). Until then neither is
     * saved, shown or asked for.
     */
    public static function hasHomeStart(): bool
    {
        // Asked often, so both answers are remembered: "yes" for good, "not
        // yet" for a minute (same as Service::hasTypes).
        $known = Cache::get('schema:home_visit_start');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('appointments', 'home_start_code');
        $ready ? Cache::forever('schema:home_visit_start', true) : Cache::put('schema:home_visit_start', false, 60);

        return $ready;
    }

    /** A start code no other booking has. */
    public static function newHomeStartCode(): string
    {
        do {
            $code = \App\Support\HomeVisitStart::newCode();
        } while (self::withTrashed()->where('home_start_code', $code)->exists());

        return $code;
    }

    // The therapist this booking asked for by name (requested_staff_id). It
    // records the request, not who did the service: it stays when someone
    // else is assigned. Null for bookings that named nobody, and for every
    // booking made before the column existed.
    public function requestedStaff()
    {
        return $this->belongsTo(Staff::class, 'requested_staff_id');
    }

    /**
     * Whether appointments.client_reschedule_count exists yet (the migration
     * has been run). Until then reschedules aren't counted or limited.
     */
    public static function countsReschedules(): bool
    {
        $known = Cache::get('schema:client_reschedule_count');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('appointments', 'client_reschedule_count');
        $ready ? Cache::forever('schema:client_reschedule_count', true) : Cache::put('schema:client_reschedule_count', false, 60);

        return $ready;
    }

    /**
     * Whether appointments.client_voucher_id exists yet (the migration has
     * been run). Until then a voucher sent with a booking is ignored.
     */
    public static function holdsVouchers(): bool
    {
        $known = Cache::get('schema:client_voucher_id');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('appointments', 'client_voucher_id');
        $ready ? Cache::forever('schema:client_voucher_id', true) : Cache::put('schema:client_voucher_id', false, 60);

        return $ready;
    }

    /**
     * Whether appointments.requested_staff_id exists yet (the migration has
     * been run). Until then nothing reads or writes it.
     */
    public static function remembersRequests(): bool
    {
        // Asked on every appointments read, so both answers are remembered:
        // "yes" for good, "not yet" for a minute.
        $known = Cache::get('schema:requested_staff');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('appointments', 'requested_staff_id');
        $ready ? Cache::forever('schema:requested_staff', true) : Cache::put('schema:requested_staff', false, 60);

        return $ready;
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    /**
     * The bookings a client missed since they last came in: no-shows dated
     * after their most recent finished visit. Attending a visit starts the
     * count again, so a client who misses one and then comes is not on the
     * way to being paused for good.
     */
    public function scopeMissedSinceLastVisit($query)
    {
        return $query->where('appointments.status', self::STATUS_NO_SHOW)
            ->whereRaw("appointments.appointment_date > COALESCE((select max(visit.appointment_date) from appointments visit where visit.client_id = appointments.client_id and visit.status = ?), '1900-01-01')", [self::STATUS_COMPLETED]);
    }

    // The voucher the client booked with (Appointment::holdsVouchers).
    public function voucher()
    {
        return $this->belongsTo(ClientVoucher::class, 'client_voucher_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function services()
    {
        return $this->hasMany(AppointmentServiceItem::class, 'appointment_id')->orderBy('sort_order');
    }

    public function packages()
    {
        return $this->hasMany(AppointmentPackage::class, 'appointment_id');
    }

    public function queue()
    {
        return $this->hasOne(Queue::class, 'appointment_id');
    }

    // Loose-in-spirit but FK'd (billings.appointment_id -> appointments.id) —
    // one billing per appointment, created once by
    // AppointmentService::proceedToBilling().
    public function billing()
    {
        return $this->hasOne(Billing::class, 'appointment_id');
    }

    public function review()
    {
        return $this->hasOne(Review::class, 'appointment_id');
    }
}
