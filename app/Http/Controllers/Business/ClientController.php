<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
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

    public function store(ClientRequest $request)
    {
        return $this->clientService->createClient($request->user(), $request->validated());
    }
}
