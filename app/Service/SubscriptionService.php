<?php

namespace App\Service;

use App\Models\User;
use App\Repository\SubscriptionRepository;
use App\Repository\SpaBusinessRepository;
use App\Http\Resources\SubscriptionResource;

class SubscriptionService
{
    private SubscriptionRepository $subscriptionRepository;
    private SpaBusinessRepository $businessRepository;

    public function __construct(
        SubscriptionRepository $subscriptionRepository,
        SpaBusinessRepository $businessRepository,
    ) {
        $this->subscriptionRepository = $subscriptionRepository;
        $this->businessRepository = $businessRepository;
    }

    public function listSubscription(int $perPage = 15)
    {
        $collection = $this->subscriptionRepository->paginate($perPage);
        return SubscriptionResource::collection($collection);
    }

    // GET /business/subscription for the logged-in business owner — not a
    // paginated admin listing, but "does my business have a subscription,
    // and if so what does it look like". Returns 200 with
    // has_subscription: false (never a 404) when there isn't one yet, so
    // the dashboard can render an empty state instead of treating a brand
    // new business as an error.
    public function getCurrentSubscription(User $user)
    {
        $business = $this->businessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json([
                'has_subscription' => false,
                'message' => 'No spa business found for this account.',
            ], 200);
        }

        $subscription = $this->subscriptionRepository->findLatestForBusiness($business->id);

        if (! $subscription) {
            return response()->json([
                'has_subscription' => false,
                'message' => 'No subscription found for this business yet.',
            ], 200);
        }

        return response()->json([
            'has_subscription' => true,
            'subscription' => new SubscriptionResource($subscription),
        ], 200);
    }

    public function createSubscription(array $payload)
    {
        $model = $this->subscriptionRepository->create($payload);
        return new SubscriptionResource($model);
    }

    public function getSubscription(string $uuid)
    {
        $model = $this->subscriptionRepository->findByUuid($uuid);
        return new SubscriptionResource($model);
    }

    public function getSubscriptionByField(string $field, $value)
    {
        $model = $this->subscriptionRepository->findByField($field, $value);
        return new SubscriptionResource($model);
    }

    public function updateSubscription(string $uuid, array $payload)
    {
        $model = $this->subscriptionRepository->update($uuid, $payload);
        return new SubscriptionResource($model);
    }

    public function deleteSubscription(string $uuid)
    {
        $this->subscriptionRepository->delete($uuid);
        return true;
    }

    public function restoreSubscription(string $uuid)
    {
        $model = $this->subscriptionRepository->restore($uuid);
        return new SubscriptionResource($model);
    }
}