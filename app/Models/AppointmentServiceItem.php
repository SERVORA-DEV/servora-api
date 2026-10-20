<?php

namespace App\Models;

use App\Repository\Business\AppointmentServiceRepository;
use App\Support\History;
use App\Support\ServiceKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Maps to the appointment_services table — named ...Item (not
// AppointmentService) to avoid colliding with
// App\Service\Business\AppointmentService, the business logic/orchestrator
// class for the appointments resource itself (same reasoning as
// PackageServiceItem vs. App\Service\Business\PackageService).
//
// A single service booked within an appointment. Multi-therapist support
// hangs off this row (therapistAssignments), not off Appointment directly —
// one appointment can have many of these, each independently assigned and
// progressed. Never deleted once created (cancelled instead) to preserve
// history — see AppointmentServiceRepository::cancel().
class AppointmentServiceItem extends Model
{
    use HasUuids;

    protected $table = 'appointment_services';

    protected $fillable = [
        'uuid',
        'appointment_id',
        'source_appointment_package_id',
        // An add-on line (App\Support\ServiceAddon): the main line it rides
        // with, and which add-on it is. It has no service_variant_id — its
        // name, minutes and price are its own copy.
        'parent_appointment_service_id',
        'addon_id',
        'service_variant_id',
        // Its own copy of what was booked (App\Support\History): the menu
        // can be edited or removed afterwards without rewriting the visit.
        'service_name',
        'duration_minutes',
        'service_type',
        // Whether a facility had to be chosen before it could start, as it
        // was when booked (null: booked before this existed).
        'facility_required',
        'service_category',
        'quantity',
        'sort_order',
        'unit_price',
        'discount_amount',
        'subtotal',
        // A package service extended to a longer length: the difference,
        // charged on top of the package (App\Support\ServiceExtension).
        'extra_charge',
        // How far it has been extended, against the owner's longest
        // extension (App\Support\FrontDeskPolicy::extension).
        'extended_minutes',
        'points_earned',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'extra_charge' => 'decimal:2',
            'facility_required' => 'boolean',
        ];
    }

    // Safety net, not the primary mechanism — every service-mutating method
    // in AppointmentService already calls recalculateAppointmentTotals()
    // explicitly (kept as-is; harmless if this also fires). This exists so
    // the appointment header's subtotal/total_amount can never silently
    // drift from its actual services again, regardless of which method (or
    // a future one) touches a row — only individual model save()/delete()
    // calls fire these events, not bulk query-builder ->update() (see
    // AppointmentService::cancelAppointment(), which still needs its own
    // explicit call for exactly that reason).
    protected static function booted(): void
    {
        $recalculate = function (self $item) {
            if ($item->appointment) {
                app(AppointmentServiceRepository::class)->recalculateAppointmentTotals($item->appointment);
            }
        };
        static::saved($recalculate);
        static::deleted($recalculate);

        // Take the copy when the line is made, and again when it is moved to
        // another length (Extend) — never because the menu changed.
        static::saving(function (self $item) {
            // (An add-on line is not a length of a service: its copy is
            // written when it is added — AppointmentService::addAddon.)
            if (History::ready() && $item->addon_id === null && ($item->isDirty('service_variant_id') || $item->service_name === null)) {
                $item->copyFromMenu();
            }
        });
    }

    /** Copies the service's name, length, type and category as they stand now. */
    public function copyFromMenu(): void
    {
        $variant = ServiceVariant::withTrashed()->find($this->service_variant_id);
        if (! $variant) {
            return;
        }
        $service = Service::withTrashed()->find($variant->service_id);

        $this->service_name = $service?->name;
        $this->duration_minutes = $variant->duration_minutes;
        $this->service_type = ServiceKind::of($service?->service_type);
        if (Service::hasFacilityRequirement()) {
            $this->facility_required = ServiceKind::needsFacility($this->service_type, (bool) $service?->facility_required);
        }
        $this->service_category = $service?->category;
    }

    // ── What it was when it was booked — its own copy first, the menu only
    //    for a line that has none (App\Support\History::own) ────────────────

    public function displayName(): ?string
    {
        return History::own($this->service_name, fn () => $this->serviceVariant?->service?->name
            ?? ($this->serviceVariant ? Service::withTrashed()->whereKey($this->serviceVariant->service_id)->value('name') : null));
    }

    public function lengthMinutes(): ?int
    {
        $minutes = History::own($this->duration_minutes, fn () => $this->serviceVariant?->duration_minutes);

        return $minutes !== null ? (int) $minutes : null;
    }

    /** time | procedure | facility (App\Support\ServiceKind). */
    public function kind(): string
    {
        return ServiceKind::of(History::own($this->service_type, fn () => $this->serviceVariant?->service?->service_type));
    }

    /** A facility has to be chosen before it can start (App\Support\ServiceKind::needsFacility). */
    public function needsFacility(): bool
    {
        if (! Service::hasFacilityRequirement()) {
            return false;
        }

        return ServiceKind::needsFacility($this->kind(), (bool) History::own(
            $this->facility_required,
            fn () => $this->serviceVariant?->service?->facility_required,
        ));
    }

    public function category(): ?string
    {
        return History::own($this->service_category, fn () => $this->serviceVariant?->service?->category);
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    // A length removed from the menu is still the length this line was for.
    public function serviceVariant()
    {
        return $this->belongsTo(ServiceVariant::class, 'service_variant_id')->withTrashed();
    }

    public function sourceAppointmentPackage()
    {
        return $this->belongsTo(AppointmentPackage::class, 'source_appointment_package_id');
    }

    // ── Add-ons (App\Support\ServiceAddon) ─────────────────────────────────

    /** This line is an add-on on another line of the same visit. */
    public function isAddon(): bool
    {
        return $this->parent_appointment_service_id !== null;
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_appointment_service_id');
    }

    public function addons()
    {
        return $this->hasMany(self::class, 'parent_appointment_service_id');
    }

    // Which add-on this line is (kept even after the owner removes it).
    public function addon()
    {
        return $this->belongsTo(Addon::class, 'addon_id')->withTrashed();
    }

    /** Its add-ons still on the visit (none until add-ons exist). */
    public function liveAddons()
    {
        return Addon::ready()
            ? $this->addons()->where('status', '!=', 'Cancelled')->orderBy('sort_order')->get()
            : collect();
    }

    public function therapistAssignments()
    {
        return $this->hasMany(TherapistAssignment::class, 'appointment_service_id');
    }
}
