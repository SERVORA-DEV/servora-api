<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\BranchOfferingsRequest;
use App\Service\Business\BranchOfferingService;

class BranchOfferingController extends Controller
{
    public function __construct(private BranchOfferingService $branchOfferingService)
    {
    }

    // Every service and package change for one branch, in one request.
    public function update(BranchOfferingsRequest $request, string $uuid)
    {
        $payload = $request->validated();

        return $this->branchOfferingService->save($request->user(), $uuid, $payload['services'] ?? [], $payload['packages'] ?? []);
    }
}
