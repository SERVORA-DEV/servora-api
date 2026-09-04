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
        $staffUuid = $request->validate(['staff_uuid' => ['required', 'uuid', 'exists:staff,uuid']])['staff_uuid'];
        return $this->frontOfficeAttendanceService->checkIn($request->user(), $staffUuid, $request);
    }

    public function checkOut(Request $request)
    {
        $staffUuid = $request->validate(['staff_uuid' => ['required', 'uuid', 'exists:staff,uuid']])['staff_uuid'];
        return $this->frontOfficeAttendanceService->checkOut($request->user(), $staffUuid, $request);
    }
}
