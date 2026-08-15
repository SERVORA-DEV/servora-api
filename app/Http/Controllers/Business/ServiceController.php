<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\BranchAvailabilityRequest;
use App\Http\Requests\Business\ServiceRequest;
use App\Service\Business\ServiceService;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    private ServiceService $serviceService;

    public function __construct(ServiceService $serviceService)
    {
        $this->serviceService = $serviceService;
    }

    public function index(Request $request)
    {
        return $this->serviceService->listServices($request->user(), $request->input('per_page', 15));
    }

    public function store(ServiceRequest $request)
    {
        return $this->serviceService->createService($request->user(), $request->validated());
    }

    public function show(Request $request, string $uuid)
    {
        return $this->serviceService->getService($request->user(), $uuid);
    }

    public function update(ServiceRequest $request, string $uuid)
    {
        return $this->serviceService->updateService($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->serviceService->deleteService($request->user(), $uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    public function updateBranches(BranchAvailabilityRequest $request, string $uuid)
    {
        return $this->serviceService->updateServiceBranches($request->user(), $uuid, $request->validated()['branches']);
    }
}
