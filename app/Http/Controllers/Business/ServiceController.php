<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\BranchAvailabilityRequest;
use App\Http\Requests\Business\ServiceRequest;
use App\Service\Business\ServiceService;
use App\Service\System\ServiceTemplateService;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    private ServiceService $serviceService;
    private ServiceTemplateService $serviceTemplateService;

    public function __construct(
        ServiceService $serviceService,
        // The admin catalog isn't business-scoped, so there's no business-side
        // service wrapping it - this controller reads the System one directly
        // for templates() below.
        ServiceTemplateService $serviceTemplateService,
    ) {
        $this->serviceService = $serviceService;
        $this->serviceTemplateService = $serviceTemplateService;
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

    // The premade catalog shown when an owner clicks Add Service. Read-only:
    // adopting one is the normal store() call above carrying
    // source_template_uuid, so the owner's edits are what actually get saved.
    public function templates(Request $request)
    {
        return $this->serviceTemplateService->listForOwners($request->input('category'));
    }

    public function updateVariantBranches(BranchAvailabilityRequest $request, string $uuid)
    {
        return $this->serviceService->updateVariantBranches($request->user(), $uuid, $request->validated()['branches']);
    }
}
