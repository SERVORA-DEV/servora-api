<?php

namespace App\Http\Controllers\System;

use App\Service\System\BranchRegistrationService;
use App\Http\Requests\System\BranchRegistrationRejectRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BranchRegistrationController extends Controller
{
    private BranchRegistrationService $branchRegistrationService;

    public function __construct(BranchRegistrationService $branchRegistrationService)
    {
        $this->branchRegistrationService = $branchRegistrationService;
    }

    public function index(Request $request)
    {
        return $this->branchRegistrationService->listPending($request->input('per_page', 15));
    }

    public function show(string $uuid)
    {
        return $this->branchRegistrationService->getRegistration($uuid);
    }

    public function approve(Request $request, string $uuid)
    {
        return $this->branchRegistrationService->approve($request->user(), $uuid, $request);
    }

    public function reject(BranchRegistrationRejectRequest $request, string $uuid)
    {
        return $this->branchRegistrationService->reject($request->user(), $uuid, $request->validated()['reason'], $request);
    }
}
