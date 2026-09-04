<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\ClientPreferredTherapistRequest;
use App\Http\Requests\Business\ClientRequest;
use App\Service\Business\ClientService;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    private ClientService $clientService;

    public function __construct(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    public function index(Request $request)
    {
        return $this->clientService->search($request->user(), $request->input('q'), $request->input('per_page', 15));
    }

    public function show(Request $request, string $uuid)
    {
        return $this->clientService->show($request->user(), $uuid);
    }

    public function store(ClientRequest $request)
    {
        return $this->clientService->createClient($request->user(), $request->validated());
    }

    public function update(ClientRequest $request, string $uuid)
    {
        return $this->clientService->updateClient($request->user(), $uuid, $request->validated());
    }

    public function setPreferredTherapist(ClientPreferredTherapistRequest $request, string $uuid)
    {
        return $this->clientService->setPreferredTherapist($request->user(), $uuid, $request->validated()['staff_uuid'] ?? null);
    }
}
