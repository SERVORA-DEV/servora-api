<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgetPasswordRequest;
use App\Http\Requests\Auth\GoogleLoginRequest;
use App\Http\Requests\Auth\TwoFactorLoginEmailCodeRequest;
use App\Http\Requests\Auth\TwoFactorLoginVerifyRequest;
use App\Http\Requests\Auth\ResendRegistrationOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyPasswordResetOtpRequest;
use App\Http\Requests\Auth\VerifyRegistrationOtpRequest;
use App\Rules\NoEmoji;
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

    // The sign-in has no form request of its own: its two credentials are held
    // to the same no-emoji rule as the forms that set them.
    private function plainCredentials(Request $request): void
    {
        $request->validate([
            'email' => [new NoEmoji()],
            'password' => [new NoEmoji()],
        ]);
    }

    public function login(Request $request)
    {
        $this->plainCredentials($request);

        return $this->userService->login($request);
    }

    // The administrators' own door (see UserService::login): the same checks,
    // but only an administrator account gets in, and only here.
    public function adminLogin(Request $request)
    {
        $this->plainCredentials($request);

        return $this->userService->login($request, asAdmin: true);
    }

    public function loginWithGoogle(GoogleLoginRequest $request)
    {
        return $this->userService->loginWithGoogle($request);
    }

    public function verificationStatus(Request $request)
    {
        $data = $request->validate(['status_token' => ['required', 'string', 'max:100']]);

        return $this->userService->verificationStatus($data['status_token']);
    }

    public function verifyTwoFactorLogin(TwoFactorLoginVerifyRequest $request)
    {
        return $this->userService->verifyTwoFactorLogin($request);
    }

    public function requestTwoFactorEmailCode(TwoFactorLoginEmailCodeRequest $request)
    {
        return $this->userService->requestTwoFactorEmailCode($request);
    }

    public function logout(Request $request)
    {
        return $this->userService->logoutUser($request->user(), $request);
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

    public function verifyRegistrationOtp(VerifyRegistrationOtpRequest $request)
    {
        return $this->userService->verifyRegistrationOtp($request->validated());
    }

    public function resendRegistrationOtp(ResendRegistrationOtpRequest $request)
    {
        return $this->userService->resendRegistrationOtp($request->validated());
    }
}
