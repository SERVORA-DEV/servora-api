<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\ChangePasswordRequest;
use App\Http\Requests\System\PersonalEmailRequest;
use App\Http\Requests\System\TwoFactorConfirmRequest;
use App\Http\Requests\System\TwoFactorStepUpRequest;
use App\Http\Requests\System\VerifyOtpRequest;
use App\Service\System\SecurityService;
use Illuminate\Http\Request;

class SecurityController extends Controller
{
    private SecurityService $securityService;

    public function __construct(SecurityService $securityService)
    {
        $this->securityService = $securityService;
    }

    public function changePassword(ChangePasswordRequest $request)
    {
        return $this->securityService->changePassword(
            $request->user(),
            $request->validated(),
            $request->user()->currentAccessToken()?->id,
        );
    }

    public function twoFactorStatus(Request $request)
    {
        return $this->securityService->twoFactorStatus($request->user());
    }

    public function enableTwoFactor(Request $request)
    {
        return $this->securityService->enableTwoFactor($request->user());
    }

    public function confirmTwoFactor(TwoFactorConfirmRequest $request)
    {
        return $this->securityService->confirmTwoFactor($request->user(), $request->validated());
    }

    public function disableTwoFactor(TwoFactorStepUpRequest $request)
    {
        return $this->securityService->disableTwoFactor($request->user(), $request->validated());
    }

    public function regenerateRecoveryCodes(TwoFactorStepUpRequest $request)
    {
        return $this->securityService->regenerateRecoveryCodes($request->user(), $request->validated());
    }

    public function submitPersonalEmail(PersonalEmailRequest $request)
    {
        return $this->securityService->submitPersonalEmail($request->user(), $request->validated());
    }

    public function resendPersonalEmailOtp(Request $request)
    {
        return $this->securityService->resendPersonalEmailOtp($request->user());
    }

    public function verifyPersonalEmail(VerifyOtpRequest $request)
    {
        return $this->securityService->verifyPersonalEmail($request->user(), $request->validated());
    }

    public function sessions(Request $request)
    {
        return $this->securityService->sessions($request->user(), $request->user()->currentAccessToken()?->id);
    }

    public function revokeSession(Request $request, int $id)
    {
        return $this->securityService->revokeSession($request->user(), $id, $request->user()->currentAccessToken()?->id);
    }
}
