<?php

namespace App\Service\System;

use App\Repository\System\SubscriptionPlanRepository;
use App\Http\Resources\SubscriptionPlanResource;
use Illuminate\Support\Arr;

class SubscriptionPlanService
{
    private SubscriptionPlanRepository $subscriptionPlanRepository;

    public function __construct(SubscriptionPlanRepository $subscriptionPlanRepository)
    {
        $this->subscriptionPlanRepository = $subscriptionPlanRepository;
    }

    public function listSubscriptionPlan(int $perPage = 15)
    {
        $collection = $this->subscriptionPlanRepository->paginate($perPage);
        return SubscriptionPlanResource::collection($collection);
    }

    public function listOfActiveSubscriptionPlan(int $perPage = 15)
    {
        $collection = $this->subscriptionPlanRepository->paginateActivePlan($perPage);

        return SubscriptionPlanResource::collection($collection);
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
        $existingActive = $this->subscriptionPlanRepository->findActiveByCategory($payload['category']);

        if ($existingActive) {
            return response()->json([
                'success' => false,
                'message' => "An active {$payload['category']} plan already exists. Update that plan instead of creating a new one.",
                'active_plan_uuid' => $existingActive->uuid,
            ], 409);
        }

        $model = $this->subscriptionPlanRepository->create($payload);

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
        $payload = Arr::except($payload, ['category']);

        if ($this->subscriptionPlanRepository->hasSubscribers($plan)) {
            $newPlan = $this->subscriptionPlanRepository->archiveAndVersion($plan, $payload);

            return (new SubscriptionPlanResource($newPlan))->additional([
                'meta' => [
                    'versioned' => true,
                    'archived_plan_uuid' => $plan->uuid,
                ],
            ]);
        }

        $updated = $this->subscriptionPlanRepository->update($uuid, $payload);

        return (new SubscriptionPlanResource($updated))->additional([
            'meta' => ['versioned' => false],
        ]);
    }

    public function deleteSubscriptionPlan(string $uuid)
    {
        $this->subscriptionPlanRepository->delete($uuid);
        return true;
    }

    public function restoreSubscriptionPlan(string $uuid)
    {
        $model = $this->subscriptionPlanRepository->restore($uuid);
        return new SubscriptionPlanResource($model);
    }
}
