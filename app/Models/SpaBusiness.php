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

        'verification_status',
        'operating_status',

        'verified_by',
        'verified_at',

        'rejection_reason',
        'suspension_reason',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function branches()
    {
        return $this->hasMany(SpaBranch::class, 'spa_business_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'spa_business_id');
    }
}
