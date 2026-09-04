<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AttendanceCorrectionRequest;
use App\Http\Requests\Business\AttendanceRequest;
use App\Service\Business\AttendanceService;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    private AttendanceService $attendanceService;

    public function __construct(AttendanceService $attendanceService)
    {
        $this->attendanceService = $attendanceService;
    }

    public function index(Request $request)
    {
        $date = $request->input('date', now()->format('Y-m-d'));
        return $this->attendanceService->listForDate($request->user(), $date);
    }

    public function store(AttendanceRequest $request)
    {
        return $this->attendanceService->markAttendance($request->user(), $request->validated(), $request);
    }

    public function report(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->subDays(29)->format('Y-m-d'));
        $dateTo = $request->input('date_to', now()->format('Y-m-d'));
        return $this->attendanceService->report($request->user(), $dateFrom, $dateTo);
    }

    // Manager-only monitoring report backing the Staff Attendance dashboard
    // (summary cards, trend/breakdown, table, issues panel) — distinct from
    // report() above, which stays the Owner's simpler read-only contract.
    public function roster(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->format('Y-m-d'));
        $dateTo = $request->input('date_to', now()->format('Y-m-d'));
        return $this->attendanceService->rosterReport($request->user(), $dateFrom, $dateTo);
    }

    public function show(Request $request, string $uuid)
    {
        return $this->attendanceService->showDetail($request->user(), $uuid);
    }

    public function update(AttendanceCorrectionRequest $request, string $uuid)
    {
        return $this->attendanceService->correctAttendance($request->user(), $uuid, $request->validated(), $request);
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->attendanceService->clearAttendance($request->user(), $uuid, $request);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    public function bulkMarkPresent(Request $request)
    {
        $date = $request->input('date', now()->format('Y-m-d'));
        return $this->attendanceService->bulkMarkPresent($request->user(), $date, $request);
    }
}
