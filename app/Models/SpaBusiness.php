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

        'business_type',
        'registration_document_type',
        'registration_document_path',
        'registered_business_name',
        'registered_owner_name',
        'authorized_representative_name',
        'registration_number',

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

    // Most recent subscription row — not necessarily status=Active (a
    // lapsed/cancelled business still has a "current" plan worth showing
    // on the system admin list), just the latest one on file.
    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class, 'spa_business_id')->latestOfMany();
    }

    public function services()
    {
        return $this->hasMany(Service::class, 'spa_business_id');
    }

    public function packages()
    {
        return $this->hasMany(Package::class, 'spa_business_id');
    }
}
