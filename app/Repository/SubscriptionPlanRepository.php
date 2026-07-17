<?php

namespace App\Repository;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SubscriptionPlanRepository
{
    public function paginate(int $perPage = 15)
    {
        return SubscriptionPlan::latest()->paginate($perPage);
    }

    public function paginateActivePlan(int $perPage = 15)
    {
        return SubscriptionPlan::where('is_active', true)
        ->latest()
        ->paginate($perPage);
    }

    public function create(array $payload)
    {
        return SubscriptionPlan::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return SubscriptionPlan::where('uuid', $uuid)->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return SubscriptionPlan::where($field, $value)->firstOrFail();
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
        $model = SubscriptionPlan::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();
        return $model;
    }
}