<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Repository\SpaBusinessRepository;
use App\Service\Business\HomeServiceReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// GET /business/home-service/readiness — the home service checklist: what each
// branch the user can see still needs before clients can book a home visit
// (HomeServiceReadiness). Read only.
class HomeServiceReadinessController extends Controller
{
    public function __construct(private SpaBusinessRepository $businesses)
    {
    }

    public function show(Request $request, HomeServiceReadiness $readiness): JsonResponse
    {
        $business = $this->businesses->findForUser($request->user());
        abort_unless($business, 404);

        return response()->json([
            'data' => $readiness->present($business->id, $this->businesses->branchesForUser($request->user())),
        ]);
    }
}
