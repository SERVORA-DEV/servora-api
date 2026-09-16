<?php

namespace App\Service\Client;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Repository\UserRepository;
use Illuminate\Database\QueryException;

class ClientProfileService
{
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * Fills in the profile fields client registration never asks for. Returns
     * a UserResource (so the response body is wrapped in `data`, same as
     * /auth/me) or a 422 if the phone number was taken between validation and
     * the write.
     */
    public function updateProfile(User $user, array $payload)
    {
        $data = [
            'first_name' => trim($payload['first_name']),
            'last_name' => trim($payload['last_name']),
            'phone_number' => trim($payload['phone_number']),
            // Stamped on first completion only, never refreshed by a later
            // edit. Nothing gates on this column — the mobile app decides
            // whether onboarding is needed from the fields themselves — but it
            // is the only record of when a client finished setup.
            'onboarding_completed_at' => $user->onboarding_completed_at ?? now(),
        ];

        // The email an account signed up with is immutable here: it is the
        // login identifier and the key for password_reset_tokens and
        // email_verification_otps, and it has already been OTP-verified.
        // Changing it behind the user's back would strand email_verified_at on
        // an address nobody proved they own. This branch only fires for an
        // account created without an email — impossible today (users.email is
        // NOT NULL and registration requires it), and here for the day a
        // phone-signup lane exists.
        if (blank($user->email) && filled($payload['email'] ?? null)) {
            $data['email'] = strtolower(trim($payload['email']));
            $data['email_verified_at'] = null;
        }

        try {
            $user = $this->userRepository->update($user, $data);
        } catch (QueryException $e) {
            // The unique index is the real guard — the FormRequest's check is
            // inherently racy, so two clients submitting the same number at
            // once can both pass validation. Reported in the same shape a 422
            // from the FormRequest would have, so the app renders it the same
            // way either path it came from.
            if ($e->getCode() === '23000') {
                return response()->json([
                    'message' => 'That mobile number is already linked to another Servora account.',
                    'errors' => [
                        'phone_number' => ['That mobile number is already linked to another Servora account.'],
                    ],
                ], 422);
            }

            throw $e;
        }

        return new UserResource($user->fresh());
    }
}
