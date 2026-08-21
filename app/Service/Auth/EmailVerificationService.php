<?php

namespace App\Service\Auth;

use App\Http\Resources\UserResource;
use App\Repository\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;

class EmailVerificationService
{
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function verifyEmail(array $payload, $id, $hash)
    {
        $frontendUrl = config('app.frontend_url');

        $user = $this->userRepository->findByField('id', $id);

        if (!$user) {
            return redirect($frontendUrl . '/verify-email?status=invalid');
        }

        if (! hash_equals((string) $hash, sha1($user->email))) {
            return redirect($frontendUrl . '/verify-email?status=invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect($frontendUrl . '/verify-email?status=already');
        }

        $user->markEmailAsVerified();

        $user->account_status = 'Active';
        $user->save();

        return redirect($frontendUrl . '/verify-email?status=success');
    }

    public function resend(array $payload)
    {
        $user = $this->userRepository->findByField('email', $payload['email']);

        if (! $user) {
            return response()->json([
                'message' => 'No account found with this email.'
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'This email is already verified. You can log in.'
            ]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Verification email sent. Please check your inbox.'
        ]);
    }
}
