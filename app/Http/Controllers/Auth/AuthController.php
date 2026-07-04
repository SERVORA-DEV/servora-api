<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Service\UserService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    private $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function adminLogin(Request $request)
    {
        return $this->userService->loginAdminUser($request);
    }

    public function ownerLogin(Request $request)
    {
        return $this->userService->loginOwnerUser($request);
    }

    public function logout(Request $request)
    {
        return $this->userService->logoutUser($request->user());
    }
}
