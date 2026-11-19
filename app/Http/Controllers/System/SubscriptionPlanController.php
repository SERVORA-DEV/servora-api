<?php

namespace App\Http\Controllers\System;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Service\System\SubscriptionPlanService;
use App\Http\Requests\SubscriptionPlanRequest;
use App\Http\Controllers\Controller;

class SubscriptionPlanController extends Controller
{
    public function __construct(
        private SubscriptionPlanService $subscriptionPlanService,
    ) {}

    public function index(Request $request)
    {
        return $this->subscriptionPlanService->listSubscriptionPlan($request->input('per_page', 15));
    }   

    //Create a Subscription Plan
    public function store(SubscriptionPlanRequest $request)
    {
        return $this->subscriptionPlanService->createSubscriptionPlan($request->validated());
    }

    public function show(string $uuid)
    {
        return $this->subscriptionPlanService->getSubscriptionPlan($uuid);
    }

    public function update(SubscriptionPlanRequest $request, string $uuid)
    {
        return $this->subscriptionPlanService->updateSubscriptionPlan($uuid, $request->validated());
    }

    public function destroy(string $uuid)
    {
        $result = $this->subscriptionPlanService->deleteSubscriptionPlan($uuid);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    //Display Active Subscription Plans
    public function displayActivePlans(Request $request)
    {
        return $this->subscriptionPlanService->listOfActiveSubscriptionPlan($request->input('per_page', 15));
    }
}