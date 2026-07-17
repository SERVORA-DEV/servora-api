<?php

namespace App\Service;

use App\Repository\SubscriptionPlanRepository;
use App\Http\Resources\SubscriptionPlanResource;

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

    public function createSubscriptionPlan(array $payload)
    {
        $model = $this->subscriptionPlanRepository->create($payload);
        return new SubscriptionPlanResource($model);
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

    public function updateSubscriptionPlan(string $uuid, array $payload)
    {
        $model = $this->subscriptionPlanRepository->update($uuid, $payload);
        return new SubscriptionPlanResource($model);
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