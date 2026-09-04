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
        'description',
        'image_path',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
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
}
