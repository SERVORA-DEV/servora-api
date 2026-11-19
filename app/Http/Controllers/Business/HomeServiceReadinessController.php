<?php

namespace App\Http\Controllers\Business;

use App\Exceptions\NotFound;
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
        throw_unless($business, NotFound::class);

        return response()->json([
            'data' => $readiness->present($business->id, $this->businesses->branchesForUser($request->user())),
        ]);
    }
}
