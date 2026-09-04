<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'source',
        'status',
        'check_in_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'remarks',
        'subtotal',
        'discount_amount',
        'total_amount',
        'reward_redemption_id',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date:Y-m-d',
            'check_in_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
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
}
