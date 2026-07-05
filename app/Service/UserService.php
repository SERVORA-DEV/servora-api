<?php

namespace App\Service;

use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Repository\UserRepository;
use App\Service\RoleService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;

class UserService
{
    private UserRepository $userRepository;
    private RoleService $roleService;

    public function __construct(UserRepository $userRepository, RoleService $roleService)
    {
        $this->userRepository = $userRepository;
        $this->roleService = $roleService;
    }

    public function getUser(string $uuid)
    {
        $user = $this->userRepository->findByField('uuid', $uuid);
        $user['role'] = $this->roleService->getRoleByField('id', $user->role_id)->name;
        return new UserResource($user);
    }

    public function login(object $payload)
    {
        if (empty($payload->email) || empty($payload->password)) {
            return response()->json([
                'message' => 'Email and password are required'
            ], 400);
        }

        $user = $this->userRepository->findByField('email', $payload->email);

        if (! $user) {
            return response()->json([
                'message' => 'User not found'
            ], 401);
        }

        if (! Hash::check($payload->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid password'
            ], 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email before logging in.'
            ], 403);
        }

        $user['role'] = $this->roleService->getRoleByField('id', $user->role_id)->name;
        $token = $user->createToken($user->email)->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 200);
    }

    public function logoutUser(object $user)
    {
        if ($user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json(['message' => 'Logged out successfully'], 200);
    }

    public function registerBusinessUser(array $payload){

        $role = $this->roleService->getRoleByField('name', 'business_owner');

        $payload['role_id'] = $role->id;

        $user = $this->userRepository->create($payload);

        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Registration successful. Please check your email to verify your account.',
        ], 201);
    }
}
