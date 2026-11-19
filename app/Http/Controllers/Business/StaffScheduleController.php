<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\DeclineRequest;
use App\Http\Requests\Business\StaffScheduleRequest;
use App\Service\Business\StaffScheduleService;
use Illuminate\Http\Request;

class StaffScheduleController extends Controller
{
    public function __construct(
        private StaffScheduleService $staffScheduleService,
    ) {}

    public function show(Request $request, string $uuid)
    {
        return $this->staffScheduleService->getSchedule($request->user(), $uuid);
    }

    // The front desk's read-only Shifts view of its own branch.
    public function branchWeek(Request $request)
    {
        return $this->staffScheduleService->branchWeek($request->user());
    }

    public function update(StaffScheduleRequest $request, string $uuid)
    {
        return $this->staffScheduleService->updateSchedule($request->user(), $uuid, $request->validated());
    }

    // ── Schedule change requests (Staff Policies → owner approval) ───────

    public function requests(Request $request)
    {
        return $this->staffScheduleService->pendingRequests($request->user());
    }

    public function approveRequest(Request $request, string $uuid)
    {
        return $this->staffScheduleService->approve($request->user(), $uuid);
    }

    public function rejectRequest(DeclineRequest $request, string $uuid)
    {
        $reason = $request->validated()['reason'] ?? null;

        return $this->staffScheduleService->reject($request->user(), $uuid, $reason);
    }

    public function withdrawRequest(Request $request, string $uuid)
    {
        return $this->staffScheduleService->withdraw($request->user(), $uuid);
    }
}
