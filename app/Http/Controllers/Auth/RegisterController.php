<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Service\UserService;
use App\Http\Requests\Auth\RegisterRequest;

class RegisterController extends Controller
{

    private $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function register(RegisterRequest $request)
    {
        return $this->userService->registerBusinessUser($request->all());
    }
}