<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Service\Auth\EmailVerificationService;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{

    private $emaiVerificationService;

    public function __construct(EmailVerificationService $emaiVerificationService)
    {
        $this->emaiVerificationService = $emaiVerificationService;
    }

    public function verify(Request $request, $id, $hash)
    {
        return $this->emaiVerificationService->verifyEmail($request->all(), $id, $hash);
    }

    // GET (signed): the button in the sign-up email. The account is created now.
    public function completeRegistration(string $token, \App\Service\UserService $users)
    {
        return redirect(rtrim((string) config('app.frontend_url'), '/') . '/verify-email?status=' . $users->completeRegistration($token));
    }

    public function resend(ResendVerificationRequest $request)
    {
        return $this->emaiVerificationService->resend($request->validated());
    }
}