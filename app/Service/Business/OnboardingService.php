<?php

namespace App\Service\Business;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Repository\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OnboardingService
{
    private UserRepository $userRepository;
    private SpaBusinessRepository $businessRepository;

    public function __construct(UserRepository $userRepository, SpaBusinessRepository $businessRepository)
    {
        $this->userRepository = $userRepository;
        $this->businessRepository = $businessRepository;
    }

    public function complete(User $user, array $payload): JsonResponse
    {
        if ($user->onboarding_completed_at) {
            return response()->json([
                'success' => false,
                'message' => 'Onboarding has already been completed.',
            ], 409);
        }

        $personal = [
            'first_name' => $payload['first_name'],
            'middle_name' => $payload['middle_name'] ?? null,
            'last_name' => $payload['last_name'],
            'suffix' => $payload['suffix'] ?? null,
            'gender' => $payload['gender'],
            'birth_date' => $payload['birth_date'],
            'phone_number' => $payload['phone_number'],
            'profile_photo' => $payload['profile_photo'] ?? null,
            'onboarding_completed_at' => now(),
        ];

        $business = [
            'owner_id' => $user->id,
            'business_name' => $payload['business_name'],
            'business_email' => $payload['business_email'],
            'business_phone' => $payload['business_phone'],
            'business_logo' => $payload['business_logo'] ?? null,
            'business_description' => $payload['business_description'] ?? null,
        ];

        $user = DB::transaction(function () use ($user, $personal, $business) {
            $user = $this->userRepository->update($user, $personal);
            $this->businessRepository->create($business);

            return $user;
        });

        return response()->json([
            'success' => true,
            'message' => 'Onboarding completed successfully.',
            'user' => new UserResource($user->fresh()),
        ], 200);
    }
}
