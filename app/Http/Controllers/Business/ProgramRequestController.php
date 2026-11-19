<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\DeclineRequest;
use App\Http\Requests\Business\ProgramRequestApproveRequest;
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
    public function approve(ProgramRequestApproveRequest $request, string $uuid)
    {
        $changed = $request->validated();

        return $this->programRequestService->approve($request->user(), $uuid, $changed ?: null);
    }

    public function decline(DeclineRequest $request, string $uuid)
    {
        $reason = $request->validated()['reason'] ?? null;

        return $this->programRequestService->decline($request->user(), $uuid, $reason);
    }
}
