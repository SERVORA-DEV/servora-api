<?php

namespace App\Service\Client;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Repository\UserRepository;
use App\Services\ImageUploadService;
use App\Support\ProfilePhoto;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;

class ClientProfileService
{
    private UserRepository $userRepository;

    private ImageUploadService $images;

    public function __construct(UserRepository $userRepository, ImageUploadService $images)
    {
        $this->userRepository = $userRepository;
        $this->images = $images;
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

        // Birthday vouchers (customer programs) read this; left as-is when
        // the app doesn't send it.
        if (array_key_exists('birth_date', $payload)) {
            $data['birth_date'] = $payload['birth_date'] ?: null;
        }

        // Spas record a client's gender; kept when the app doesn't send it,
        // and never cleared from here.
        if (filled($payload['gender'] ?? null)) {
            $data['gender'] = $payload['gender'];
        }

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

    /**
     * Sets the client's profile picture. The new file is stored first and the
     * old one only removed afterwards (ImageUploadService::replace), so a failed
     * upload never costs them the picture they have. An older picture that is a
     * data URL or a link has no file of ours to delete.
     */
    public function setPhoto(User $user, UploadedFile $file)
    {
        $old = $user->profile_photo;
        $keep = ProfilePhoto::isExternal($old) ? null : $old;
        $path = $this->images->replace($keep, $file, "clients/{$user->uuid}/profile");

        $user = $this->userRepository->update($user, ['profile_photo' => $path]);

        return new UserResource($user->fresh());
    }

    /** Takes the client's own picture down: back to the default avatar. */
    public function removePhoto(User $user)
    {
        $old = $user->profile_photo;
        $user = $this->userRepository->update($user, ['profile_photo' => null]);
        if ($old && ! ProfilePhoto::isExternal($old)) {
            $this->images->delete($old);
        }

        return new UserResource($user->fresh());
    }
}
