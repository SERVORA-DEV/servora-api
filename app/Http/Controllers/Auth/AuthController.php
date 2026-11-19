<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgetPasswordRequest;
use App\Http\Requests\Auth\GoogleLoginRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\TwoFactorLoginEmailCodeRequest;
use App\Http\Requests\Auth\TwoFactorLoginVerifyRequest;
use App\Http\Requests\Auth\ResendRegistrationOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerificationStatusRequest;
use App\Http\Requests\Auth\VerifyPasswordResetOtpRequest;
use App\Http\Requests\Auth\VerifyRegistrationOtpRequest;
use App\Service\Auth\GoogleSignInService;
use App\Service\Auth\PasswordResetService;
use App\Service\Auth\RegistrationService;
use App\Service\Auth\SignInService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private SignInService $signIn,
        private GoogleSignInService $googleSignIn,
        private PasswordResetService $passwordReset,
        private RegistrationService $registration,
    ) {}

    public function getUser(Request $request)
    {
        return $this->signIn->getUser($request->user()->uuid);
    }

    public function login(LoginRequest $request)
    {
        return $this->signIn->login($request);
    }

    // The administrators' own door (see SignInService::login): the same checks,
    // but only an administrator account gets in, and only here.
    public function adminLogin(LoginRequest $request)
    {
        return $this->signIn->login($request, asAdmin: true);
    }

    public function loginWithGoogle(GoogleLoginRequest $request)
    {
        return $this->googleSignIn->loginWithGoogle($request);
    }

    public function verificationStatus(VerificationStatusRequest $request)
    {
        $data = $request->validated();

        return $this->registration->verificationStatus($data['status_token']);
    }

    public function verifyTwoFactorLogin(TwoFactorLoginVerifyRequest $request)
    {
        return $this->signIn->verifyTwoFactorLogin($request);
    }

    public function requestTwoFactorEmailCode(TwoFactorLoginEmailCodeRequest $request)
    {
        return $this->signIn->requestTwoFactorEmailCode($request);
    }

    public function logout(Request $request)
    {
        return $this->signIn->logoutUser($request->user(), $request);
    }

    public function forgetPassword(ForgetPasswordRequest $request)
    {
        return $this->passwordReset->forgetPassword($request->validated());
    }

    public function verifyForgetPasswordOtp(VerifyPasswordResetOtpRequest $request)
    {
        return $this->passwordReset->verifyForgetPasswordOtp($request->validated());
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        return $this->passwordReset->resetPassword($request->validated());
    }

    public function verifyRegistrationOtp(VerifyRegistrationOtpRequest $request)
    {
        return $this->registration->verifyRegistrationOtp($request->validated());
    }

    public function resendRegistrationOtp(ResendRegistrationOtpRequest $request)
    {
        return $this->registration->resendRegistrationOtp($request->validated());
    }
}
