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
}
