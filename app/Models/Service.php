<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Service extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'services';

    protected $fillable = [
        'uuid',
        'spa_business_id',
        'created_by',
        'name',
        'code',
        'category',
        // 'time' | 'procedure' | 'facility' — App\Support\ServiceKind.
        'service_type',
        // A facility has to be chosen before the front desk may start it
        // (App\Support\ServiceKind::needsFacility).
        'facility_required',
        'description',
        'image_path',
        'is_active',
        // The owner's "can be done at a client's home" tick.
        'home_service_available',
        'is_template',
        'source_template_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'home_service_available' => 'boolean',
            'facility_required' => 'boolean',
            'is_template' => 'boolean',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    /**
     * Whether the service-type columns exist yet (the migration has been
     * run): services.service_type and appointment_services.extra_charge,
     * added together. Until then every service reads as time-based and
     * nothing writes either column.
     */
    public static function hasTypes(): bool
    {
        // Asked often, so both answers are remembered: "yes" for good, "not
        // yet" for a minute (same as Appointment::remembersRequests).
        $known = Cache::get('schema:service_types');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('services', 'service_type') && Schema::hasColumn('appointment_services', 'extra_charge');
        $ready ? Cache::forever('schema:service_types', true) : Cache::put('schema:service_types', false, 60);

        return $ready;
    }

    /**
     * Whether the service-parts table and its assignment columns exist yet (migration
     * 2026_11_16_090000). Until then every service is done by one person and nothing reads or
     * writes parts.
     */
    public static function hasParts(): bool
    {
        $known = Cache::get('schema:service_parts');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasTable('service_parts') && Schema::hasColumn('therapist_assignments', 'service_part_id');
        $ready ? Cache::forever('schema:service_parts', true) : Cache::put('schema:service_parts', false, 60);

        return $ready;
    }

    /**
     * Whether the facility-requirement columns exist yet (the migration has
     * been run). Until then nothing needs a facility and nothing writes them.
     */
    public static function hasFacilityRequirement(): bool
    {
        $known = Cache::get('schema:facility_required');
        if (is_bool($known)) {
            return $known;
        }
        $ready = Schema::hasColumn('services', 'facility_required') && Schema::hasColumn('appointment_services', 'facility_required');
        $ready ? Cache::forever('schema:facility_required', true) : Cache::put('schema:facility_required', false, 60);

        return $ready;
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }

    public function variants()
    {
        return $this->hasMany(ServiceVariant::class, 'service_id')->orderBy('duration_minutes');
    }

    public function facilities()
    {
        return $this->belongsToMany(Facility::class, 'facility_services');
    }

    // Done by several staff at once: one part each ("Upper body", "Lower body"), each with its
    // share of the commission. None: one person does it all.
    public function parts()
    {
        return $this->hasMany(ServicePart::class, 'service_id')->orderBy('sort')->orderBy('id');
    }

    /** How many staff work on one booking of it together: one per part, or one. */
    public function staffNeeded(): int
    {
        if (! self::hasParts()) {
            return 1;
        }
        $count = $this->relationLoaded('parts') ? $this->parts->count() : $this->parts()->count();

        return max(1, $count);
    }

    /** Staff needed per service, for many services in one query (service id => count). */
    public static function staffNeededFor(iterable $serviceIds): array
    {
        $ids = collect($serviceIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        if (! self::hasParts()) {
            return $ids->mapWithKeys(fn ($id) => [$id => 1])->all();
        }
        $counts = ServicePart::whereIn('service_id', $ids)->selectRaw('service_id, count(*) as n')->groupBy('service_id')->pluck('n', 'service_id');

        return $ids->mapWithKeys(fn ($id) => [$id => max(1, (int) ($counts[$id] ?? 0))])->all();
    }

    // The admin-authored template this service was copied from, if any.
    // Provenance only — the copy is fully independent, so nothing here is ever
    // read back to update it (see ServiceService::createService).
    public function sourceTemplate()
    {
        return $this->belongsTo(Service::class, 'source_template_id');
    }

    // Every business service copied from this template — powers the admin
    // catalog's "adopted by N" counter via withCount('adoptions').
    public function adoptions()
    {
        return $this->hasMany(Service::class, 'source_template_id');
    }
}
