<?php

namespace App\Repository\System;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanRepository
{
    public function paginate(int $perPage = 15)
    {
        return SubscriptionPlan::withCount('subscriptions')
        ->latest()
        ->paginate($perPage);
    }

    public function paginateActivePlan(int $perPage = 15)
    {
        return SubscriptionPlan::withCount('subscriptions')
        ->where('is_active', true)
        ->latest()
        ->paginate($perPage);
    }

    public function create(array $payload)
    {
        return SubscriptionPlan::create($payload)->fresh();
    }

    public function findByUuid(string $uuid)
    {
        return SubscriptionPlan::withCount('subscriptions')
        ->where('uuid', $uuid)
        ->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return SubscriptionPlan::withCount('subscriptions')
        ->where($field, $value)
        ->firstOrFail();
    }

    public function findActiveByCategory(string $category)
    {
        return SubscriptionPlan::where('category', $category)
        ->where('is_active', true)
        ->first();
    }

    public function hasSubscribers(SubscriptionPlan $plan): bool
    {
        return $plan->subscriptions()->exists();
    }

    public function update(string $uuid, array $payload)
    {
        $model = SubscriptionPlan::where('uuid', $uuid)->firstOrFail();
        $model->update($payload);
        return $model->fresh();
    }

    /**
     * Archive the given plan (is_active = false) and create the next version
     * of it in the same category. Used when a plan already has subscribers
     * attached, so editing it in place would silently rewrite the terms
     * those subscribers signed up under.
     */
    public function archiveAndVersion(SubscriptionPlan $plan, array $payload): SubscriptionPlan
    {
        return DB::transaction(function () use ($plan, $payload) {
            $plan->update(['is_active' => false]);

            $payload['category'] = $plan->category;
            $payload['is_active'] = true;

            return $this->create($payload);
        });
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
