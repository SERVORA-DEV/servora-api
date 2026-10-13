<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\ProgramRequestRequest;
use App\Service\Business\ProgramRequestService;
use Illuminate\Http\Request;

class ProgramRequestController extends Controller
{
    public function __construct(private ProgramRequestService $programRequestService)
    {
    }

    public function index(Request $request)
    {
        return $this->programRequestService->list($request->user());
    }

    public function store(ProgramRequestRequest $request)
    {
        return $this->programRequestService->create($request->user(), $request->validated());
    }

    // The manager who asked takes their own request back.
    public function destroy(Request $request, string $uuid)
    {
        return $this->programRequestService->withdraw($request->user(), $uuid);
    }

    // With no body: created as asked. With a program in the body: the
    // owner's changed version is created instead.
    public function approve(Request $request, string $uuid)
    {
        $changed = $request->validate(['name' => ['sometimes', 'string', 'min:2', 'max:120']] + ProgramRequestRequest::programRules());

        return $this->programRequestService->approve($request->user(), $uuid, $changed ?: null);
    }

    public function decline(Request $request, string $uuid)
    {
        $reason = $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null;

        return $this->programRequestService->decline($request->user(), $uuid, $reason);
    }
}
