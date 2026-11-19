<?php

namespace App\Http\Controllers\System;

use App\Http\Requests\System\BranchListRequest;
use App\Http\Requests\System\SuspendReasonRequest;
use App\Service\System\BranchService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function __construct(
        private BranchService $branchService,
    ) {}

    // One page of branches, filtered by the API (BranchRepository::paginateAll).
    public function index(BranchListRequest $request)
    {
        $filters = array_filter($request->only(['search', 'business', 'status', 'operating']), fn ($v) => $v !== 'all');

        return $this->branchService->listBranches(\App\Support\PerPage::from($request), $filters);
    }

    public function show(string $uuid)
    {
        return $this->branchService->getBranch($uuid);
    }

    public function suspensionImpact(string $uuid)
    {
        return $this->branchService->suspensionImpact($uuid);
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
