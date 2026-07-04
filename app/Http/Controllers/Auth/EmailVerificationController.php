<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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
}