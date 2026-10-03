<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Service\UserService;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\RegisterClientRequest;

class RegisterController extends Controller
{

    private $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function register(RegisterRequest $request)
    {
        // Whitelist: only credentials. Never pass raw input, which could carry
        // fillable fields such as email_verified_at or account_status.
        // terms_accepted is required by RegisterRequest; the service stamps
        // the agreement itself, so it isn't passed along.
        return $this->userService->registerBusinessUser($request->safe()->only(['email', 'password']));
    }

    public function registerClient(RegisterClientRequest $request)
    {
        return $this->userService->registerClientUser($request->validated());
    }
}