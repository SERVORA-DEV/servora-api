<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\ClientListRequest;
use App\Http\Requests\Business\ClientRequest;
use App\Service\Business\ClientBookingPauseService;
use App\Service\Business\ClientDuplicateService;
use App\Service\Business\ClientService;
use App\Support\PerPage;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct(
        private ClientService $clientService,
        private ClientDuplicateService $duplicates,
    ) {}

    // Client search (name, phone, email: `q`), and the owner's Clients list with its segment,
    // branch and sort (ClientRepository::search).
    public function index(ClientListRequest $request)
    {
        $filters = $request->only(['segment', 'branch_uuid', 'sort']);

        return $this->clientService->search($request->user(), $request->input('q'), PerPage::from($request), $filters);
    }

    // The Clients page's figures (how many, new, regulars, booked ahead, not back lately, spent).
    public function summary(Request $request)
    {
        return $this->clientService->summary($request->user());
    }

    public function show(Request $request, string $uuid)
    {
        return $this->clientService->show($request->user(), $uuid);
    }

    public function bookingPauses(Request $request, string $uuid, ClientBookingPauseService $booking)
    {
        return response()->json(['data' => $booking->pauses($request, $uuid)]);
    }

    public function unpauseAppBooking(Request $request, string $uuid, string $branchUuid, ClientBookingPauseService $booking)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $booking->unpause($request, $uuid, $branchUuid, $data['reason']);

        return response()->noContent();
    }

    public function duplicateSuggestions(Request $request, string $uuid)
    {
        return response()->json(['data' => $this->duplicates->suggestions($request->user(), $uuid)]);
    }

    public function mergeDuplicate(Request $request, string $uuid, string $otherUuid)
    {
        $validated = $request->validate(['keep_uuid' => ['required', 'uuid']]);

        return response()->json(['data' => $this->duplicates->resolve($request->user(), $uuid, $otherUuid, 'merge', $validated['keep_uuid'])]);
    }

    public function dismissDuplicate(Request $request, string $uuid, string $otherUuid)
    {
        return response()->json(['data' => $this->duplicates->resolve($request->user(), $uuid, $otherUuid, 'different', null)]);
    }

    public function store(ClientRequest $request)
    {
        return $this->clientService->createClient($request->user(), $request->validated());
    }

    public function update(ClientRequest $request, string $uuid)
    {
        return $this->clientService->updateClient($request->user(), $uuid, $request->validated());
    }

    public function dismissPhoto(Request $request, string $uuid)
    {
        return $this->clientService->dismissPhoto($request->user(), $uuid);
    }
}
