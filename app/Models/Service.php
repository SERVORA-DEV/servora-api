<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'description',
        'image_path',
        'is_active',
        'is_template',
        'source_template_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_template' => 'boolean',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
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
