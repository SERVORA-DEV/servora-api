<?php

namespace App\Http\Controllers\Business;

use App\Service\Business\SpaBranchService;
use App\Http\Requests\Business\SpaBranchRequest;
use App\Http\Requests\Business\SpaBranchUpdateRequest;
use App\Http\Requests\Business\SpaBranchLocationRequest;
use App\Http\Requests\Business\SpaBranchPermitRequest;
use App\Http\Requests\Client\PublicTherapistAvailabilityRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SpaBranchController extends Controller
{
    private SpaBranchService $spaBranchService;

    public function __construct(SpaBranchService $spaBranchService)
    {
        $this->spaBranchService = $spaBranchService;
    }

    public function index(Request $request)
    {
        return $this->spaBranchService->listSpaBranch($request->user(), $request->input('per_page', 15));
    }

    // Public, unauthenticated — see routes/api.php and NearbySpaResource.
    public function nearby(Request $request)
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radius_km' => 'nullable|numeric|min:1|max:100',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        return $this->spaBranchService->nearby(
            (float) $validated['lat'],
            (float) $validated['lng'],
            isset($validated['radius_km']) ? (float) $validated['radius_km'] : null,
            isset($validated['limit']) ? (int) $validated['limit'] : null,
        );
    }

    // Public, unauthenticated — see routes/api.php (must stay registered
    // after 'nearby' so this {uuid} wildcard doesn't shadow it) and
    // BranchDetailResource for what's exposed here.
    public function publicShow(string $uuid)
    {
        return $this->spaBranchService->publicShow($uuid);
    }

    // Public, unauthenticated — the client booking flow's therapist step
    // calls this once it knows the date/time, since publicShow()'s
    // 'therapists' key can't say who is actually free then.
    public function publicTherapistAvailability(PublicTherapistAvailabilityRequest $request, string $uuid)
    {
        return $this->spaBranchService->publicTherapistAvailability($uuid, $request->validated());
    }

    public function store(SpaBranchRequest $request)
    {
        return $this->spaBranchService->createSpaBranch($request->user(), $request->validated());
    }

    public function show(Request $request, string $uuid)
    {
        return $this->spaBranchService->getSpaBranch($request->user(), $uuid);
    }

    public function update(SpaBranchUpdateRequest $request, string $uuid)
    {
        return $this->spaBranchService->updateSpaBranch($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->spaBranchService->deleteSpaBranch($request->user(), $uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->spaBranchService->restoreSpaBranch($uuid);
    }

    public function saveLocation(SpaBranchLocationRequest $request, string $uuid)
    {
        return $this->spaBranchService->saveLocation($request->user(), $uuid, $request->validated(), $request);
    }

    public function savePermit(SpaBranchPermitRequest $request, string $uuid)
    {
        return $this->spaBranchService->savePermit(
            $request->user(),
            $uuid,
            $request->validated(),
            $request->file('permit_document'),
            $request
        );
    }

    public function submit(Request $request, string $uuid)
    {
        return $this->spaBranchService->submit($request->user(), $uuid, $request);
    }
}