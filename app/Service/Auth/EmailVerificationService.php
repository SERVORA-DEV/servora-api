<?php

namespace App\Service\Auth;

use App\Http\Resources\UserResource;
use App\Repository\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

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
        // A sign-up still waiting for its button: send that email again.
        $user = $this->userRepository->findByEmail($payload['email'], 'web');
        if (! $user && ($again = \App\Support\PendingRegistration::resend($payload['email'])) !== null) {
            return $again
                ? response()->json(['message' => 'Confirmation email sent. Please check your inbox.'])
                : response()->json(['message' => 'We could not send the confirmation email right now. Please try again in a moment.'], 503);
        }

        // Owner-side verification links only; client accounts verify by OTP.

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

        // An explicit user request for an email, so a failed send is reported
        // rather than papered over — see UserService::resendRegistrationOtp
        // for the same reasoning on the OTP side.
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            Log::error('Failed to resend email verification link', [
                'email' => $user->email,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'We could not send the verification email right now. Please try again in a moment.'
            ], 503);
        }

        return response()->json([
            'message' => 'Verification email sent. Please check your inbox.'
        ]);
    }
}
