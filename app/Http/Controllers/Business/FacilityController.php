<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\FacilityRequest;
use App\Service\Business\FacilityService;
use Illuminate\Http\Request;

class FacilityController extends Controller
{
    private FacilityService $facilityService;

    public function __construct(FacilityService $facilityService)
    {
        $this->facilityService = $facilityService;
    }

    public function index(Request $request)
    {
        return $this->facilityService->listFacilities($request->user(), $request->input('per_page', 15));
    }

    public function store(FacilityRequest $request)
    {
        return $this->facilityService->createFacility($request->user(), $request->validated());
    }

    public function show(Request $request, string $uuid)
    {
        return $this->facilityService->getFacility($request->user(), $uuid);
    }

    public function update(FacilityRequest $request, string $uuid)
    {
        return $this->facilityService->updateFacility($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->facilityService->deleteFacility($request->user(), $uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
}
