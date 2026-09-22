<?php

namespace App\Http\Controllers\System;

use App\Http\Requests\System\SuspendReasonRequest;
use App\Service\System\BusinessService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    private BusinessService $businessService;

    public function __construct(BusinessService $businessService)
    {
        $this->businessService = $businessService;
    }

    public function index(Request $request)
    {
        return $this->businessService->listBusinesses($request->input('per_page', 100));
    }

    public function show(string $uuid)
    {
        return $this->businessService->getBusiness($uuid);
    }

    public function suspend(SuspendReasonRequest $request, string $uuid)
    {
        return $this->businessService->suspend($request->user(), $uuid, $request->validated()['reason'], $request);
    }

    public function reactivate(Request $request, string $uuid)
    {
        return $this->businessService->reactivate($request->user(), $uuid, $request);
    }
}
