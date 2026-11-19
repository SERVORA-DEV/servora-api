<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterClientRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Service\Auth\RegistrationService;

class RegisterController extends Controller
{
    public function __construct(private RegistrationService $registration) {}

    public function register(RegisterRequest $request)
    {
        // Whitelist: only credentials. Never pass raw input, which could carry
        // fillable fields such as email_verified_at or account_status.
        // terms_accepted is required by RegisterRequest; the service stamps
        // the agreement itself, so it isn't passed along.
        return $this->registration->registerBusinessUser($request->safe()->only(['email', 'password']));
    }

    public function registerClient(RegisterClientRequest $request)
    {
        return $this->registration->registerClientUser($request->validated());
    }
}
