<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\BranchClosureRequest;
use App\Service\Business\BranchClosureService;
use Illuminate\Http\Request;

class BranchClosureController extends Controller
{
    public function __construct(private BranchClosureService $branchClosureService)
    {
    }

    public function index(Request $request, string $uuid)
    {
        return $this->branchClosureService->list($request->user(), $uuid);
    }

    // The bookings a closure for this window would land on.
    public function conflicts(BranchClosureRequest $request, string $uuid)
    {
        return $this->branchClosureService->conflicts($request->user(), $uuid, $request->validated());
    }

    public function store(BranchClosureRequest $request, string $uuid)
    {
        return $this->branchClosureService->create($request->user(), $uuid, $request->validated());
    }

    public function update(BranchClosureRequest $request, string $uuid, string $closure)
    {
        return $this->branchClosureService->update($request->user(), $uuid, $closure, $request->validated());
    }

    // Removes one that hasn't started; ends one that is running.
    public function destroy(Request $request, string $uuid, string $closure)
    {
        return $this->branchClosureService->remove($request->user(), $uuid, $closure);
    }
}
