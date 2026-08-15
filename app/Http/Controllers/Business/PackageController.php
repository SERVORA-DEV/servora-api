<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\BranchAvailabilityRequest;
use App\Http\Requests\Business\PackageRequest;
use App\Service\Business\PackageService;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    private PackageService $packageService;

    public function __construct(PackageService $packageService)
    {
        $this->packageService = $packageService;
    }

    public function index(Request $request)
    {
        return $this->packageService->listPackages($request->user(), $request->input('per_page', 15));
    }

    public function store(PackageRequest $request)
    {
        return $this->packageService->createPackage($request->user(), $request->validated());
    }

    public function show(Request $request, string $uuid)
    {
        return $this->packageService->getPackage($request->user(), $uuid);
    }

    public function update(PackageRequest $request, string $uuid)
    {
        return $this->packageService->updatePackage($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->packageService->deletePackage($request->user(), $uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    public function updateBranches(BranchAvailabilityRequest $request, string $uuid)
    {
        return $this->packageService->updatePackageBranches($request->user(), $uuid, $request->validated()['branches']);
    }
}
