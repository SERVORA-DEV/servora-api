<?php

namespace App\Repository\System;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanRepository
{
    public function paginate(int $perPage = 15)
    {
        return SubscriptionPlan::withCount(['subscriptions', 'subscriptions as active_subscribers_count' => fn ($q) => self::live($q)])
        ->latest()
        ->paginate($perPage);
    }

    // A subscription that's paid up right now: Active and not past its
    // expires_at. What "subscribers" means on the admin Plans page and for
    // Most Popular.
    public static function live($query)
    {
        return $query->where('status', 'Active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    // The tier with the most live subscribers, counting every version of it
    // (an owner still on an archived Basic is a Basic subscriber). A tie
    // goes to the tier that most recently gained a subscriber; no live
    // subscribers at all means no tier is "most popular".
    public function mostPopularCategory(): ?string
    {
        return self::live(Subscription::query())
            ->join('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.subscription_plan_id')
            ->groupBy('subscription_plans.category')
            ->orderByRaw('count(*) desc')
            ->orderByRaw('max(subscriptions.created_at) desc')
            ->value('subscription_plans.category');
    }

    // Live subscribers per tier, every version counted — e.g. ['Basic' => 3].
    public function liveSubscribersByCategory(): array
    {
        return self::live(Subscription::query())
            ->join('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.subscription_plan_id')
            ->groupBy('subscription_plans.category')
            ->selectRaw('subscription_plans.category, count(*) as total')
            ->pluck('total', 'category')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    public function findOtherActiveInCategory(SubscriptionPlan $plan): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('category', $plan->category)
            ->where('is_active', true)
            ->where('id', '!=', $plan->id)
            ->first();
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
        return SubscriptionPlan::withCount(['subscriptions', 'subscriptions as active_subscribers_count' => fn ($q) => self::live($q)])
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

    // Also counts owners only PENDING a move to this plan (a version created
    // by an earlier edit — see PlanChangeService): editing it in place would
    // silently change terms they were already told about or accepted, so it
    // must be versioned (and they re-asked) too.
    // Likewise an owner who scheduled a downgrade to this plan
    // (PlanSwitchService) — they chose it on its current terms.
    public function hasSubscribers(SubscriptionPlan $plan): bool
    {
        return $plan->subscriptions()->exists()
            || Subscription::where('pending_plan_id', $plan->id)->exists()
            || Subscription::where('scheduled_plan_id', $plan->id)->exists();
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
            // The new version replaces this one as the category's plan —
            // active unless the admin switched it off in the same edit.
            $payload['is_active'] = (bool) ($payload['is_active'] ?? true);

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
