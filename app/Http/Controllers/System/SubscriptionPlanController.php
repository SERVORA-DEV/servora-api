<?php

namespace App\Http\Controllers\System;

use Illuminate\Http\Request;
use App\Service\Admin\SubscriptionPlanService;
use App\Http\Requests\SubscriptionPlanRequest;
use App\Http\Controllers\Controller;

class SubscriptionPlanController extends Controller
{
    private SubscriptionPlanService $subscriptionPlanService;

    public function __construct(SubscriptionPlanService $subscriptionPlanService)
    {
        $this->subscriptionPlanService = $subscriptionPlanService;
    }

    public function index(Request $request)
    {
        return $this->subscriptionPlanService->listSubscriptionPlan($request->input('per_page', 15));
    }   


    //Create a Subscription Plan
    public function store(SubscriptionPlanRequest $request)
    {
        return $this->subscriptionPlanService->createSubscriptionPlan($request->all());
    }

    public function show(string $uuid)
    {
        return $this->subscriptionPlanService->getSubscriptionPlan($uuid);
    }

    public function update(SubscriptionPlanRequest $request, string $uuid)
    {
        return $this->subscriptionPlanService->updateSubscriptionPlan($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->subscriptionPlanService->deleteSubscriptionPlan($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->subscriptionPlanService->restoreSubscriptionPlan($uuid);
    }




    //Display Active Subscription Plans
    public function displayActivePlans(Request $request)
    {
        return $this->subscriptionPlanService->listOfActiveSubscriptionPlan($request->input('per_page', 15));
    }
}