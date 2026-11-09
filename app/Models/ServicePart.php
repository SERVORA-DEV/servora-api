<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One part of a service done by several staff at once, e.g. "Upper body" of a four-hands
 * massage: one staff member does it, for its share of the commission (a service's parts
 * total 100%). A service with no parts is done by one person.
 */
class ServicePart extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'service_parts';

    protected $fillable = ['uuid', 'service_id', 'name', 'share_percent', 'sort'];

    protected function casts(): array
    {
        return ['share_percent' => 'decimal:2', 'sort' => 'integer'];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
