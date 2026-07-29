<?php

namespace App\Service;

use App\Http\Resources\UserResource;
use App\Mail\PasswordResetOtpMail;
use App\Repository\UserRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;

class UserService
{
    private UserRepository $userRepository;

    private const OTP_EXPIRY_MINUTES = 10;
    private const RESET_TOKEN_EXPIRY_MINUTES = 10;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function getUser(string $uuid)
    {
        $user = $this->userRepository->findByField('uuid', $uuid);
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

    public function forgetPassword(array $payload)
    {
        $user = $this->userRepository->findByField('email', $payload['email']);

        if (! $user) {
            return response()->json([
                'message' => 'No account found with this email.'
            ], 404);
        }

        if ($user->role === 'system_administrator') {
            return response()->json([
                'message' => 'System administrator accounts cannot be reset through this process.'
            ], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email before resetting your password.'
            ], 403);
        }

        $otp = (string) random_int(100000, 999999);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($otp),
                'created_at' => now(),
            ]
        );

        Mail::to($user->email)->send(new PasswordResetOtpMail($otp, self::OTP_EXPIRY_MINUTES));

        return response()->json([
            'message' => 'A one-time password has been sent to your email.'
        ], 200);
    }

    public function verifyForgetPasswordOtp(array $payload)
    {
        $user = $this->userRepository->findByField('email', $payload['email']);

        if (! $user) {
            return response()->json([
                'message' => 'No account found with this email.'
            ], 404);
        }

        $record = DB::table('password_reset_tokens')->where('email', $user->email)->first();

        if (! $record) {
            return response()->json([
                'message' => 'No password reset request found for this email.'
            ], 404);
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::OTP_EXPIRY_MINUTES)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            return response()->json([
                'message' => 'This code has expired. Please request a new one.'
            ], 410);
        }

        if (! Hash::check($payload['otp'], $record->token)) {
            return response()->json([
                'message' => 'Invalid code.'
            ], 422);
        }

        $resetToken = Str::random(64);

        DB::table('password_reset_tokens')->where('email', $user->email)->update([
            'token' => Hash::make($resetToken),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Code verified. You may now reset your password.',
            'reset_token' => $resetToken,
        ], 200);
    }

    public function resetPassword(array $payload)
    {
        $user = $this->userRepository->findByField('email', $payload['email']);

        if (! $user) {
            return response()->json([
                'message' => 'No account found with this email.'
            ], 404);
        }

        $record = DB::table('password_reset_tokens')->where('email', $user->email)->first();

        if (! $record || ! Hash::check($payload['reset_token'], $record->token)) {
            return response()->json([
                'message' => 'Invalid or expired reset token.'
            ], 422);
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::RESET_TOKEN_EXPIRY_MINUTES)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            return response()->json([
                'message' => 'This reset session has expired. Please start again.'
            ], 410);
        }

        $this->userRepository->update($user, [
            'password' => $payload['password'],
        ]);

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password reset successfully. Please log in with your new password.'
        ], 200);
    }

    public function registerBusinessUser(array $payload){

        $payload['role'] = 'business_owner';

        $user = DB::transaction(function () use ($payload) {
            $user = $this->userRepository->create($payload);

            // Owners get full authority over their own business by default —
            // grant every permission scoped to business_owner (config/permission.php).
            $permissionPayload = array_fill_keys(config('permission.business_owner'), true);
            $permissionPayload['user_id'] = $user->id;
            $this->userRepository->createPermission($permissionPayload);

            return $user;
        });

        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Registration successful. Please check your email to verify your account.',
        ], 201);
    }
}
