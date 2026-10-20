<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\CommissionPlanRequest;
use App\Service\Business\CommissionPlanService;
use Illuminate\Http\Request;

class CommissionPlanController extends Controller
{
    public function __construct(private CommissionPlanService $commissionPlanService)
    {
    }

    public function index(Request $request)
    {
        return $this->commissionPlanService->list($request->user());
    }

    public function store(CommissionPlanRequest $request)
    {
        return $this->commissionPlanService->create($request->user(), $request->validated());
    }

    public function update(CommissionPlanRequest $request, string $uuid)
    {
        return $this->commissionPlanService->update($request->user(), $uuid, $request->validated());
    }

    public function destroy(Request $request, string $uuid)
    {
        return $this->commissionPlanService->delete($request->user(), $uuid);
    }
}
