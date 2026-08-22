<?php

namespace App\Http\Controllers\System;

use Illuminate\Http\Request;
use App\Service\System\DashboardService;
use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    private DashboardService $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function index(Request $request)
    {
        return $this->dashboardService->getOverview();
    }
}
