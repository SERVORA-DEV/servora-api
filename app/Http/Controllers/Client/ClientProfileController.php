<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ClientProfileRequest;
use App\Service\Client\ClientProfileService;

class ClientProfileController extends Controller
{
    private ClientProfileService $clientProfileService;

    public function __construct(ClientProfileService $clientProfileService)
    {
        $this->clientProfileService = $clientProfileService;
    }

    public function update(ClientProfileRequest $request)
    {
        return $this->clientProfileService->updateProfile($request->user(), $request->validated());
    }
}
