<?php

namespace App\Http\Controllers\System;

use App\Http\Requests\System\SuspendReasonRequest;
use App\Service\System\BranchService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    private BranchService $branchService;

    public function __construct(BranchService $branchService)
    {
        $this->branchService = $branchService;
    }

    public function index(Request $request)
    {
        return $this->branchService->listBranches($request->input('per_page', 100));
    }

    public function show(string $uuid)
    {
        return $this->branchService->getBranch($uuid);
    }

    public function suspend(SuspendReasonRequest $request, string $uuid)
    {
        return $this->branchService->suspend($request->user(), $uuid, $request->validated()['reason'], $request);
    }

    public function reactivate(Request $request, string $uuid)
    {
        return $this->branchService->reactivate($request->user(), $uuid, $request);
    }
}
