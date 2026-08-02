<?php

namespace App\Http\Controllers\Business;

use App\Service\Business\BranchScheduleService;
use App\Http\Requests\Business\BranchScheduleRequest;
use App\Http\Requests\Business\BranchScheduleUpdateRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BranchScheduleController extends Controller
{
    private BranchScheduleService $branchScheduleService;

    public function __construct(BranchScheduleService $branchScheduleService)
    {
        $this->branchScheduleService = $branchScheduleService;
    }

    // Listing is always scoped to one branch (spa_branch_uuid) — there's no
    // "all schedules" view for an owner, only "this branch's week".
    public function index(Request $request)
    {
        $request->validate(['spa_branch_uuid' => 'required|string']);
        return $this->branchScheduleService->listBranchSchedule($request->user(), $request->input('spa_branch_uuid'));
    }

    public function store(BranchScheduleRequest $request)
    {
        return $this->branchScheduleService->createBranchSchedule($request->user(), $request->validated());
    }

    public function show(Request $request, string $uuid)
    {
        return $this->branchScheduleService->getBranchSchedule($request->user(), $uuid);
    }

    public function update(BranchScheduleUpdateRequest $request, string $uuid)
    {
        return $this->branchScheduleService->updateBranchSchedule($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->branchScheduleService->deleteBranchSchedule($request->user(), $uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    public function restore(string $uuid)
    {
        return $this->branchScheduleService->restoreBranchSchedule($uuid);
    }
}
