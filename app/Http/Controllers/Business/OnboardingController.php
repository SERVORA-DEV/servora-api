<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\OnboardingRequest;
use App\Service\Business\OnboardingService;

class OnboardingController extends Controller
{
    private OnboardingService $onboardingService;

    public function __construct(OnboardingService $onboardingService)
    {
        $this->onboardingService = $onboardingService;
    }

    public function store(OnboardingRequest $request)
    {
        return $this->onboardingService->complete($request->user(), $request->validated());
    }
}
