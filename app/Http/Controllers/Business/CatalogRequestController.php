<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\CatalogRequestRequest;
use App\Service\Business\CatalogRequestService;
use Illuminate\Http\Request;

class CatalogRequestController extends Controller
{
    public function __construct(private CatalogRequestService $catalogRequestService)
    {
    }

    public function index(Request $request)
    {
        return $this->catalogRequestService->list($request->user(), $request->query('scope'));
    }

    public function store(CatalogRequestRequest $request)
    {
        return $this->catalogRequestService->create($request->user(), $request->validated());
    }

    // The manager who asked takes their own request back.
    public function destroy(Request $request, string $uuid)
    {
        return $this->catalogRequestService->withdraw($request->user(), $uuid);
    }

    public function added(Request $request, string $uuid)
    {
        return $this->catalogRequestService->markAdded($request->user(), $uuid);
    }

    public function decline(Request $request, string $uuid)
    {
        $reason = $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null;

        return $this->catalogRequestService->decline($request->user(), $uuid, $reason);
    }
}
