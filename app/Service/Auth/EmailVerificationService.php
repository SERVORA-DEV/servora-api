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
      $user = $this->userRepository->findByField('id', $id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.'
            ], 404);
        }

        if (! hash_equals((string) $hash, sha1($user->email))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification link.'
            ], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'Email is already verified.'
            ]);
        }

        $user->markEmailAsVerified();

        $user->account_status = 'Active';
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully.'
        ]);
    }
}
