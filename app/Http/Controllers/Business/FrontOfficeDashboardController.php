<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\Business\FrontOfficeDashboardService;
use Illuminate\Http\Request;

class FrontOfficeDashboardController extends Controller
{
    public function __construct(
        private FrontOfficeDashboardService $frontOfficeDashboardService,
    ) {}

    public function index(Request $request)
    {
        return $this->frontOfficeDashboardService->getOverview($request->user(), $request->input('scope', 'overview'));
    }
}
