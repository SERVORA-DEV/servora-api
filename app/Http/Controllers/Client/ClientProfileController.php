<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ClientHomeAddressRequest;
use App\Http\Requests\Client\ClientProfilePhotoRequest;
use App\Http\Requests\Client\ClientProfileRequest;
use App\Service\Client\ClientProfileService;
use Illuminate\Http\Request;

class ClientProfileController extends Controller
{
    public function __construct(
        private ClientProfileService $clientProfileService,
    ) {}

    public function update(ClientProfileRequest $request)
    {
        return $this->clientProfileService->updateProfile($request->user(), $request->validated());
    }

    public function setPhoto(ClientProfilePhotoRequest $request)
    {
        return $this->clientProfileService->setPhoto($request->user(), $request->file('photo'));
    }

    public function removePhoto(Request $request)
    {
        return $this->clientProfileService->removePhoto($request->user());
    }

    // The client's saved home, offered on home-service bookings.
    public function setHomeAddress(ClientHomeAddressRequest $request)
    {
        return $this->clientProfileService->setHomeAddress($request->user(), $request->validated());
    }

    public function clearHomeAddress(Request $request)
    {
        return $this->clientProfileService->clearHomeAddress($request->user());
    }
}
