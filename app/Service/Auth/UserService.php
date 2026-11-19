<?php

namespace App\Service\Auth;

use App\Service\Auth\GoogleSignInService;
use App\Service\Auth\PasswordResetService;
use App\Service\Auth\RegistrationService;
use App\Service\Auth\SignInService;
use Illuminate\Http\Request;

/**
 * Accounts, from signing up to signing in and out, in one place to look: each step is handed to
 * the class that does it. New code calls those classes directly; this stays for the callers
 * written against it.
 *
 *   Auth\SignInService         who is signed in, password sign-in, the second factor, sign-out
 *   Auth\GoogleSignInService   "Continue with Google", on the owners' site and in the app
 *   Auth\PasswordResetService  forgot password: code, reset token, new password
 *   Auth\RegistrationService   owner sign-up by email link, client sign-up by code
 *
 * The one-time code rules all three share are Concerns\ChecksOtpCodes.
 */
class UserService
{
    public function __construct(
        private SignInService $signIn,
        private GoogleSignInService $googleSignIn,
        private PasswordResetService $passwordReset,
        private RegistrationService $registration,
    ) {}

    public function getUser(string $uuid)
    {
        return $this->signIn->getUser($uuid);
    }

    public function login(object $payload, bool $asAdmin = false)
    {
        return $this->signIn->login($payload, $asAdmin);
    }

    public function loginWithGoogle(Request $request)
    {
        return $this->googleSignIn->loginWithGoogle($request);
    }

    public function googleClientSignIn(array $claims, Request $request)
    {
        return $this->googleSignIn->googleClientSignIn($claims, $request);
    }

    public function verifyTwoFactorLogin(object $payload)
    {
        return $this->signIn->verifyTwoFactorLogin($payload);
    }

    public function requestTwoFactorEmailCode(object $payload)
    {
        return $this->signIn->requestTwoFactorEmailCode($payload);
    }

    public function logoutUser(object $user, ?Request $request = null)
    {
        return $this->signIn->logoutUser($user, $request);
    }

    public function forgetPassword(array $payload)
    {
        return $this->passwordReset->forgetPassword($payload);
    }

    public function verifyForgetPasswordOtp(array $payload)
    {
        return $this->passwordReset->verifyForgetPasswordOtp($payload);
    }

    public function resetPassword(array $payload)
    {
        return $this->passwordReset->resetPassword($payload);
    }

    public function registerBusinessUser(array $payload)
    {
        return $this->registration->registerBusinessUser($payload);
    }

    public function completeRegistration(string $token): string
    {
        return $this->registration->completeRegistration($token);
    }

    public function verificationStatus(string $token)
    {
        return $this->registration->verificationStatus($token);
    }

    public function registerClientUser(array $payload)
    {
        return $this->registration->registerClientUser($payload);
    }

    public function resendRegistrationOtp(array $payload)
    {
        return $this->registration->resendRegistrationOtp($payload);
    }

    public function verifyRegistrationOtp(array $payload)
    {
        return $this->registration->verifyRegistrationOtp($payload);
    }
}
