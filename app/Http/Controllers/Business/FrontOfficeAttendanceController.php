<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\Business\FrontOfficeAttendanceService;
use Illuminate\Http\Request;

class FrontOfficeAttendanceController extends Controller
{
    private FrontOfficeAttendanceService $frontOfficeAttendanceService;

    public function __construct(FrontOfficeAttendanceService $frontOfficeAttendanceService)
    {
        $this->frontOfficeAttendanceService = $frontOfficeAttendanceService;
    }

    public function index(Request $request)
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;
        return $this->frontOfficeAttendanceService->today($request->user(), $date);
    }

    public function checkIn(Request $request)
    {
        $data = $request->validate([
            'staff_uuid' => ['required', 'uuid', 'exists:staff,uuid'],
            'covering_for_staff_uuid' => ['nullable', 'uuid', 'exists:staff,uuid'],
        ]);
        return $this->frontOfficeAttendanceService->checkIn($request->user(), $data['staff_uuid'], $data['covering_for_staff_uuid'] ?? null, $request);
    }

    public function checkOut(Request $request)
    {
        $staffUuid = $request->validate(['staff_uuid' => ['required', 'uuid', 'exists:staff,uuid']])['staff_uuid'];
        return $this->frontOfficeAttendanceService->checkOut($request->user(), $staffUuid, $request);
    }

    public function leave(Request $request)
    {
        $data = $request->validate([
            'staff_uuid' => ['required', 'uuid', 'exists:staff,uuid'],
            'type' => ['required', 'in:early,day'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        return $this->frontOfficeAttendanceService->leave($request->user(), $data['staff_uuid'], $data['type'], $data['reason'] ?? null, $request);
    }

    public function fillInCandidates(Request $request)
    {
        return $this->frontOfficeAttendanceService->fillInCandidates($request->user());
    }

    public function history(Request $request, string $uuid)
    {
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:90']])['days'] ?? 30);
        return $this->frontOfficeAttendanceService->history($request->user(), $uuid, $days);
    }
}
