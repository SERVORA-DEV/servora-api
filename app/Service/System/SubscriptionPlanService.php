<?php

namespace App\Service\System;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Repository\System\SubscriptionPlanRepository;
use App\Http\Resources\SubscriptionPlanResource;
use App\Repository\AuditLogRepository;
use App\Service\PlanChangeService;
use Illuminate\Support\Arr;

class SubscriptionPlanService
{
    private SubscriptionPlanRepository $subscriptionPlanRepository;
    private PlanChangeService $planChangeService;
    private AuditLogRepository $auditLogRepository;

    public function __construct(
        SubscriptionPlanRepository $subscriptionPlanRepository,
        PlanChangeService $planChangeService,
        AuditLogRepository $auditLogRepository,
    ) {
        $this->subscriptionPlanRepository = $subscriptionPlanRepository;
        $this->planChangeService = $planChangeService;
        $this->auditLogRepository = $auditLogRepository;
    }

    public function listSubscriptionPlan(int $perPage = 15)
    {
        $collection = $this->subscriptionPlanRepository->paginate($perPage);
        $this->markMostPopular($collection);

        return SubscriptionPlanResource::collection($collection)->additional([
            'meta' => [
                // Businesses paid up right now, across every plan version.
                'active_subscribers' => SubscriptionPlanRepository::live(Subscription::query())->count(),
                'most_popular_category' => $this->subscriptionPlanRepository->mostPopularCategory(),
                // Per tier, all versions — what the active plan's card shows.
                'subscribers_by_category' => (object) $this->subscriptionPlanRepository->liveSubscribersByCategory(),
            ],
        ]);
    }

    public function listOfActiveSubscriptionPlan(int $perPage = 15)
    {
        // Public pricing — the same for everyone, so cached until a plan or
        // subscription changes (AppCache 'plans-public' version).
        $request = request();
        $key = sprintf('plans:public:%d:%d:%s:v%d', $perPage, (int) $request->input('page', 1),
            md5($request->getSchemeAndHttpHost()), \App\Support\AppCache::version('plans-public'));

        return response()->json(\App\Support\AppCache::remember($key, 600, function () use ($perPage) {
            $collection = $this->subscriptionPlanRepository->paginateActivePlan($perPage);
            $this->markMostPopular($collection);

            return SubscriptionPlanResource::collection($collection)->response()->getData(true);
        }));
    }

    // "Most Popular" is earned: the active plan of whichever tier has the
    // most live subscribers (see SubscriptionPlanRepository::
    // mostPopularCategory). Nobody subscribed yet → no badge anywhere.
    private function markMostPopular($plans): void
    {
        $popular = $this->subscriptionPlanRepository->mostPopularCategory();

        foreach ($plans as $plan) {
            $plan->setAttribute('is_most_popular', $popular !== null && $plan->is_active && $plan->category === $popular);
        }
    }

    /**
     * Only one active plan per category is allowed at a time (there are
     * always exactly 3 categories, and each one shows a single active plan
     * to subscribers). Creating is therefore only for a category that
     * currently has no active plan — changing an existing category's plan
     * goes through updateSubscriptionPlan() instead, which knows how to
     * version it safely.
     */
    public function createSubscriptionPlan(array $payload)
    {
        $payload = $this->withTrialPricing($payload, $payload['category']);
        $existingActive = $this->subscriptionPlanRepository->findActiveByCategory($payload['category']);

        if ($existingActive) {
            return response()->json([
                'success' => false,
                'message' => "An active {$payload['category']} plan already exists. Update that plan instead of creating a new one.",
                'active_plan_uuid' => $existingActive->uuid,
            ], 409);
        }

        $model = $this->subscriptionPlanRepository->create($payload);

        $this->auditLogRepository->recordAdminAction('subscription_plans', $model->id, 'Create', null, [
            'name' => $model->name,
            'category' => $model->category,
            'monthly_price' => $model->monthly_price,
            'yearly_price' => $model->yearly_price,
        ]);

        return (new SubscriptionPlanResource($model))->additional([
            'meta' => ['versioned' => false],
        ]);
    }

    public function getSubscriptionPlan(string $uuid)
    {
        $model = $this->subscriptionPlanRepository->findByUuid($uuid);
        return new SubscriptionPlanResource($model);
    }

    public function getSubscriptionPlanByField(string $field, $value)
    {
        $model = $this->subscriptionPlanRepository->findByField($field, $value);
        return new SubscriptionPlanResource($model);
    }

    /**
     * category is intentionally immutable here — it identifies which of the
     * 3 fixed slots (Basic/Premium/Enterprise) this plan belongs to, and
     * changing it out from under an update would fight the versioning logic
     * below (which relies on the archived plan and its replacement sharing
     * a category).
     *
     * If the plan being edited already has subscribers attached, mutating
     * it in place would silently rewrite the terms those subscribers signed
     * up under (and any historical billing that references this plan's
     * price at the time). So instead: archive the current plan and create
     * the new version as the active plan for that category. Callers should
     * check the response's meta.versioned flag — when true, meta contains
     * the archived plan's uuid and the returned resource is a *new* plan
     * with a new uuid, not the one that was requested.
     */
    public function updateSubscriptionPlan(string $uuid, array $payload)
    {
        $plan = $this->subscriptionPlanRepository->findByUuid($uuid);
        $payload = $this->withTrialPricing(Arr::except($payload, ['category']), $plan->category);
        [$old, $new] = $this->auditLogRepository->diff($plan->only($plan->getFillable()), $payload);
        $changed = array_keys($new ?? []);

        // Saved without changing anything — don't create a pointless version.
        if (! $changed) {
            return (new SubscriptionPlanResource($plan))->additional(['meta' => ['versioned' => false]]);
        }

        // Only one active plan per category.
        if (($payload['is_active'] ?? $plan->is_active) && ! $plan->is_active
            && ($current = $this->subscriptionPlanRepository->findOtherActiveInCategory($plan))) {
            return response()->json([
                'success' => false,
                'message' => "“{$current->name}” is already the active {$plan->category} plan. Deactivate it first, then activate this one.",
                'active_plan_uuid' => $current->uuid,
            ], 409);
        }

        // Switching a plan on or off doesn't change its terms, so it's an
        // in-place update even with subscribers — they keep their plan until
        // their term ends; it just can't be picked by anyone new.
        if (array_diff($changed, ['is_active']) === []) {
            $updated = $this->subscriptionPlanRepository->update($uuid, ['is_active' => (bool) $payload['is_active']]);
            $this->auditLogRepository->recordAdminAction('subscription_plans', $updated->id, 'Update', $old, array_merge($new, ['name' => $updated->name]));

            return (new SubscriptionPlanResource($this->subscriptionPlanRepository->findByUuid($uuid)))->additional([
                'meta' => ['versioned' => false],
            ]);
        }

        // The Free Trial plan isn't something owners paid for, so there are no
        // terms to protect: it is edited in place, and businesses on a trial
        // get the change straight away (no version, no accept/decline prompt).
        if ($this->subscriptionPlanRepository->hasSubscribers($plan) && ! $plan->isTrial()) {
            // An older version kept only for the owners still on it — its
            // terms are what they paid for.
            if (! $plan->is_active) {
                return response()->json([
                    'message' => 'This is an older version kept for the businesses still on it, so its terms can\'t change. Edit the current plan instead.',
                ], 422);
            }

            $newPlan = $this->subscriptionPlanRepository->archiveAndVersion($plan, $payload);

            // Current subscribers keep the archived terms until their term
            // ends — ask each owner to accept the new version for renewal
            // or let the subscription end (PlanChangeService).
            $notified = $this->planChangeService->notifySubscribers($plan, $newPlan);

            $this->auditLogRepository->recordAdminAction('subscription_plans', $newPlan->id, 'Update', $old, array_merge($new ?? [], [
                'name' => $newPlan->name,
                'versioned' => true,
                'subscribers_notified' => $notified,
            ]));

            return (new SubscriptionPlanResource($newPlan))->additional([
                'meta' => [
                    'versioned' => true,
                    'archived_plan_uuid' => $plan->uuid,
                    'subscribers_notified' => $notified,
                ],
            ]);
        }

        $updated = $this->subscriptionPlanRepository->update($uuid, $payload);

        $this->auditLogRepository->recordAdminAction('subscription_plans', $updated->id, 'Update', $old, array_merge($new ?? [], [
            'name' => $updated->name,
        ]));

        return (new SubscriptionPlanResource($updated))->additional([
            'meta' => ['versioned' => false],
        ]);
    }

    // The Free Trial plan is never sold: whatever the form sends, it is saved
    // as free with no yearly price.
    private function withTrialPricing(array $payload, string $category): array
    {
        if ($category !== SubscriptionPlan::TRIAL_CATEGORY) {
            return $payload;
        }

        return array_merge($payload, ['monthly_price' => 0, 'yearly_price' => null, 'billing_cycle' => 'Monthly']);
    }

    public function deleteSubscriptionPlan(string $uuid)
    {
        $plan = $this->subscriptionPlanRepository->findByUuid($uuid);

        if ($plan->isTrial()) {
            return response()->json([
                'message' => 'The Free Trial plan can\'t be deleted. Deactivate it to stop offering the free trial.',
            ], 422);
        }

        // Subscriptions, invoices and scheduled renewals point at this plan;
        // deleting it would leave them showing "Unknown plan".
        if ($this->subscriptionPlanRepository->hasSubscribers($plan)) {
            return response()->json([
                'message' => 'Businesses have subscribed to this plan, so it can\'t be deleted. Deactivate it instead — no one new can choose it.',
            ], 422);
        }

        $this->subscriptionPlanRepository->delete($uuid);

        $this->auditLogRepository->recordAdminAction('subscription_plans', $plan->id, 'Delete', null, ['name' => $plan->name]);

        return true;
    }

    public function restoreSubscriptionPlan(string $uuid)
    {
        $model = $this->subscriptionPlanRepository->restore($uuid);

        $this->auditLogRepository->recordAdminAction('subscription_plans', $model->id, 'Restore', null, ['name' => $model->name]);

        return new SubscriptionPlanResource($model);
    }
}
