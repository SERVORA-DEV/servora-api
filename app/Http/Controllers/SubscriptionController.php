<?php

namespace App\Http\Controllers;

use App\Service\Subscription\SubscriptionService;
use App\Http\Requests\SubscriptionRequest;
use App\Http\Requests\PlanChangeDecisionRequest;
use App\Http\Requests\PlanSwitchRequest;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {}

    public function index(Request $request)
    {
        return $this->subscriptionService->getCurrentSubscription($request->user());
    }

    public function capacity(Request $request)
    {
        return $this->subscriptionService->capacity($request->user());
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

    // Owner upgrading (pay the difference, applies now) or downgrading
    // (applies at the next billing) — see PlanSwitchService.
    // Start the one free trial (config/trial.php).
    public function startTrial(Request $request)
    {
        return $this->subscriptionService->startTrial($request->user());
    }

    public function quotePlanChange(PlanSwitchRequest $request)
    {
        return $this->subscriptionService->quotePlanChange($request->user(), $request->validated());
    }

    public function changePlan(PlanSwitchRequest $request)
    {
        return $this->subscriptionService->changePlan($request->user(), $request->validated());
    }

    public function cancelScheduledPlanChange(Request $request)
    {
        return $this->subscriptionService->cancelScheduledPlanChange($request->user());
    }

}