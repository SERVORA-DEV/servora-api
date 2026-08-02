<?php

namespace App\Http\Controllers\Business;

use App\Service\Business\SpaBranchService;
use App\Http\Requests\Business\SpaBranchRequest;
use App\Http\Requests\Business\SpaBranchUpdateRequest;
use App\Http\Requests\Business\SpaBranchRegistrationRequest;
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

    public function submitRegistration(SpaBranchRegistrationRequest $request, string $uuid)
    {
        return $this->spaBranchService->submitRegistration($request->user(), $uuid, $request->validated(), $request);
    }
}