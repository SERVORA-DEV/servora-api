<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpaBusiness extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid',
        'owner_id',

        'business_name',
        'business_email',
        'business_phone',

        'business_logo',
        'business_description',

        'operating_status',
    ];

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
