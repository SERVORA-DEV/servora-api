<?php

namespace App\Http\Controllers;

use App\Service\SubscriptionService;
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

    public function store(Request $request)
    {
        return $this->subscriptionService->createSubscription($request->all());
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