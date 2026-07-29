<?php

namespace App\Repository;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SubscriptionRepository
{
    public function paginate(int $perPage = 15)
    {
        return Subscription::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Subscription::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Subscription::where('uuid', $uuid)->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return Subscription::where($field, $value)->firstOrFail();
    }

    // The most recent subscription a business has ever had — active,
    // expired, or cancelled. "Current" means latest, not "active only",
    // so an expired/cancelled subscription still surfaces its own status
    // instead of silently falling back to "no subscription".
    public function findLatestForBusiness(int $spaBusinessId)
    {
        return Subscription::with('plan')
            ->where('spa_business_id', $spaBusinessId)
            ->latest()
            ->first();
    }

    public function update(string $uuid, array $payload)
    {
        $model = $this->findByUuid($uuid);
        $model->update($payload);
        return $model;
    }

    public function delete(string $uuid)
    {
        $model = $this->findByUuid($uuid);
        return $model->delete();
    }

    public function restore(string $uuid)
    {
        $model = Subscription::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();
        return $model;
    }
}