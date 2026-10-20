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
