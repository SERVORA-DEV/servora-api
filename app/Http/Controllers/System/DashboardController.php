<?php

namespace App\Http\Controllers\System;

use Illuminate\Http\Request;
use App\Service\System\DashboardService;
use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService,
    ) {}

    public function index(Request $request)
    {
        return $this->dashboardService->getOverview();
    }
}
