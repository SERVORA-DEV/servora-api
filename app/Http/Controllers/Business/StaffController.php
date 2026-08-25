<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StaffRequest;
use App\Http\Requests\Business\StaffServiceRequest;
use App\Service\Business\StaffService;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    private StaffService $staffService;

    public function __construct(StaffService $staffService)
    {
        $this->staffService = $staffService;
    }

    public function index(Request $request)
    {
        return $this->staffService->listStaff($request->user(), $request->input('per_page', 15));
    }

    public function store(StaffRequest $request)
    {
        return $this->staffService->createStaff($request->user(), $request->validated());
    }

    public function show(Request $request, string $uuid)
    {
        return $this->staffService->getStaff($request->user(), $uuid);
    }

    public function update(StaffRequest $request, string $uuid)
    {
        return $this->staffService->updateStaff($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->staffService->deleteStaff($request->user(), $uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    public function updateServices(StaffServiceRequest $request, string $uuid)
    {
        return $this->staffService->updateServices($request->user(), $uuid, $request->validated()['service_uuids'] ?? []);
    }
}
