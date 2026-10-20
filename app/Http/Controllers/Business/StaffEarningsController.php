<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\Business\StaffEarningsReport;
use Illuminate\Http\Request;

// The Earnings page: every staff member's earnings for a pay period, less
// attendance penalties (StaffEarningsReport).
class StaffEarningsController extends Controller
{
    public function index(Request $request, StaffEarningsReport $report)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $report->report($request->user(), $data['from'] ?? null, $data['to'] ?? null);
    }
}
