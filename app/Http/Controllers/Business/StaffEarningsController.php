<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StaffEarningsRequest;
use App\Service\Business\StaffEarningsReport;

// The Earnings page: every staff member's earnings for a pay period, less
// attendance penalties (StaffEarningsReport).
class StaffEarningsController extends Controller
{
    public function index(StaffEarningsRequest $request, StaffEarningsReport $report)
    {
        $data = $request->validated();

        return $report->report($request->user(), $data['from'] ?? null, $data['to'] ?? null);
    }
}
