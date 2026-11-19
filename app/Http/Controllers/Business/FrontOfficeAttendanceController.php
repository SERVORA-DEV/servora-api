<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AttendanceDayRequest;
use App\Http\Requests\Business\AttendanceHistoryRequest;
use App\Http\Requests\Business\FillInCandidatesRequest;
use App\Http\Requests\Business\StaffCheckInRequest;
use App\Http\Requests\Business\StaffCheckOutRequest;
use App\Http\Requests\Business\StaffLeaveRequest;
use App\Service\Business\FrontOfficeAttendanceService;
use Carbon\Carbon;

class FrontOfficeAttendanceController extends Controller
{
    public function __construct(
        private FrontOfficeAttendanceService $frontOfficeAttendanceService,
    ) {}

    public function index(AttendanceDayRequest $request)
    {
        $date = $request->validated()['date'] ?? null;
        return $this->frontOfficeAttendanceService->today($request->user(), $date);
    }

    public function checkIn(StaffCheckInRequest $request)
    {
        $data = $request->validated();
        return $this->frontOfficeAttendanceService->checkIn($request->user(), $data['staff_uuid'], $data['covering_for_staff_uuid'] ?? null, $request);
    }

    public function checkOut(StaffCheckOutRequest $request)
    {
        $staffUuid = $request->validated()['staff_uuid'];
        return $this->frontOfficeAttendanceService->checkOut($request->user(), $staffUuid, $request);
    }

    public function leave(StaffLeaveRequest $request)
    {
        $data = $request->validated();
        return $this->frontOfficeAttendanceService->leave($request->user(), $data['staff_uuid'], $data['type'], $data['reason'] ?? null, $request);
    }

    public function fillInCandidates(FillInCandidatesRequest $request)
    {
        $data = $request->validated();

        return $this->frontOfficeAttendanceService->fillInCandidates($request->user(), $data['covering_for'] ?? null);
    }

    public function history(AttendanceHistoryRequest $request, string $uuid)
    {
        $data = $request->validated();
        if (! empty($data['from'])) {
            $from = Carbon::parse($data['from']);
            $to = Carbon::parse($data['to']);
            if ($from->gt($to)) {
                return response()->json(['message' => 'The start date is after the end date.'], 422);
            }
            if ($from->diffInDays($to) > 92) {
                return response()->json(['message' => 'Pick a range of three months or less.'], 422);
            }
        }

        return $this->frontOfficeAttendanceService->history($request->user(), $uuid, (int) ($data['days'] ?? 30), $data['from'] ?? null, $data['to'] ?? null);
    }
}
