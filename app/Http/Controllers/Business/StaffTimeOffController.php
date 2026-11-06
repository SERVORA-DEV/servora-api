<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StaffTimeOffRequest;
use App\Service\Business\StaffTimeOffService;
use Illuminate\Http\Request;

class StaffTimeOffController extends Controller
{
    public function __construct(private StaffTimeOffService $staffTimeOffService)
    {
    }

    public function index(Request $request, string $uuid)
    {
        return $this->staffTimeOffService->list($request->user(), $uuid);
    }

    public function store(StaffTimeOffRequest $request, string $uuid)
    {
        return $this->staffTimeOffService->create($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid, string $timeOffUuid)
    {
        return $this->staffTimeOffService->delete($request->user(), $uuid, $timeOffUuid);
    }
}
