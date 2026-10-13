<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StaffScheduleRequest;
use App\Service\Business\StaffScheduleService;
use Illuminate\Http\Request;

class StaffScheduleController extends Controller
{
    private StaffScheduleService $staffScheduleService;

    public function __construct(StaffScheduleService $staffScheduleService)
    {
        $this->staffScheduleService = $staffScheduleService;
    }

    public function show(Request $request, string $uuid)
    {
        return $this->staffScheduleService->getSchedule($request->user(), $uuid);
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

    public function rejectRequest(Request $request, string $uuid)
    {
        $reason = $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null;

        return $this->staffScheduleService->reject($request->user(), $uuid, $reason);
    }

    public function withdrawRequest(Request $request, string $uuid)
    {
        return $this->staffScheduleService->withdraw($request->user(), $uuid);
    }
}
