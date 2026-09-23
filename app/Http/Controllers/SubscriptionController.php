<?php

namespace App\Http\Controllers;

use App\Service\SubscriptionService;
use App\Http\Requests\SubscriptionRequest;
use App\Http\Requests\PlanChangeDecisionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SubscriptionController extends Controller
{
    private SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    public function index(Request $request)
    {
        return $this->subscriptionService->getCurrentSubscription($request->user());
    }

    public function store(SubscriptionRequest $request)
    {
        return $this->subscriptionService->createSubscription($request->user(), $request->validated());
    }

    public function confirm(Request $request, string $referenceId)
    {
        return response()->json(
            $this->subscriptionService->confirmPendingPayment($request->user(), $referenceId)
        );
    }

    // Owner's answer to an admin plan change: accept the updated plan for
    // their next renewal, or decline and let the subscription end.
    public function respondToPlanChange(PlanChangeDecisionRequest $request)
    {
        return $this->subscriptionService->respondToPlanChange($request->user(), $request->validated('decision'));
    }

    public function show(string $uuid)
    {
        return $this->subscriptionService->getSubscription($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->subscriptionService->updateSubscription($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->subscriptionService->deleteSubscription($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->subscriptionService->restoreSubscription($uuid);
    }
}