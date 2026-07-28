<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\OnboardingRequest;
use App\Service\Owner\OnboardingService;

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
