<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceTemplateRequest;
use App\Service\System\ServiceTemplateService;
use Illuminate\Http\Request;

class ServiceTemplateController extends Controller
{
    private ServiceTemplateService $serviceTemplateService;

    public function __construct(ServiceTemplateService $serviceTemplateService)
    {
        $this->serviceTemplateService = $serviceTemplateService;
    }

    public function index(Request $request)
    {
        return $this->serviceTemplateService->listTemplates(
            $request->input('category'),
            $request->input('per_page', 15)
        );
    }

    public function store(ServiceTemplateRequest $request)
    {
        return $this->serviceTemplateService->createTemplate($request->user(), $request->validated());
    }

    public function show(string $uuid)
    {
        return $this->serviceTemplateService->getTemplate($uuid);
    }

    public function update(ServiceTemplateRequest $request, string $uuid)
    {
        return $this->serviceTemplateService->updateTemplate($uuid, $request->validated());
    }

    public function destroy(string $uuid)
    {
        $this->serviceTemplateService->deleteTemplate($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
}
