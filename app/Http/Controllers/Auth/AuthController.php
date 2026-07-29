<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgetPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyPasswordResetOtpRequest;
use App\Service\UserService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    private $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function getUser(Request $request)
    {
        return $this->userService->getUser($request->user()->uuid);
    }

    public function login(Request $request)
    {
        return $this->userService->login($request);
    }

    public function logout(Request $request)
    {
        return $this->userService->logoutUser($request->user());
    }

    public function forgetPassword(ForgetPasswordRequest $request)
    {
        return $this->userService->forgetPassword($request->validated());
    }

    public function verifyForgetPasswordOtp(VerifyPasswordResetOtpRequest $request)
    {
        return $this->userService->verifyForgetPasswordOtp($request->validated());
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        return $this->userService->resetPassword($request->validated());
    }
}
