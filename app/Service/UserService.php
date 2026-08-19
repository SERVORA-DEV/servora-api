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

        // Business-scoped login — only sent by the branded
        // /login/{business_uuid} page (see the "Copy Login Link" button and
        // AccountCreatedModal on the Account Management page). Restricted
        // to manager/front_officer
        // accounts that actually belong to that business; an otherwise
        // valid owner/admin/client login, or a staff account from a
        // different business, is rejected here even though the
        // credentials themselves checked out above.
        if (! empty($payload->business_uuid)) {
            if (! in_array($user->role, ['manager', 'front_officer'], true)) {
                return response()->json([
                    'message' => 'This login page is only for staff accounts of this business.'
                ], 403);
            }

            $userBusinessUuid = $user->accountBranch?->branch?->business?->uuid;

            if (! $userBusinessUuid || $userBusinessUuid !== $payload->business_uuid) {
                return response()->json([
                    'message' => 'This account doesn\'t belong to this business.'
                ], 403);
            }
        } elseif (in_array($user->role, ['manager', 'front_officer'], true)) {
            // Mirror of the block above — staff accounts only ever sign in
            // through their business's own branded page, never the generic
            // owner/admin one. No business_uuid in the response — leaking
            // which business an email belongs to from an unauthenticated
            // login attempt is its own info-disclosure risk.
            return response()->json([
                'message' => 'Staff accounts sign in from your business\'s own staff login page.',
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

        // Local dev previously auto-verified accounts instantly to skip email
        // delivery. Commented out so verification behaves identically in
        // every environment — mail is configured with a real Mailtrap
        // sandbox account, so the email actually sends; view it at
        // mailtrap.io and click the link.
        // $verified = app()->environment('local');
        $verified = false;

        if ($verified) {
            $user->markEmailAsVerified();
            $user->account_status = 'Active';
            $user->save();
        } else {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'success' => true,
            // Explicit flag rather than making the frontend pattern-match the
            // message string — see RegisterForm.vue, which branches its
            // success screen on this.
            'verified' => $verified,
            'message' => $verified
                ? 'Registration successful. You can sign in now.'
                : 'Registration successful. Please check your email to verify your account.',
        ], 201);
    }

    public function registerClientUser(array $payload)
    {
        $payload['role'] = 'client';

        // No permission grant here (unlike registerBusinessUser) — client
        // isn't a key in config/permission.php, and clients don't manage a
        // business — so a single create() call needs no transaction wrapper.
        $user = $this->userRepository->create($payload);

        // Local dev previously auto-verified accounts instantly to skip email
        // delivery. Commented out so verification behaves identically in
        // every environment — mail is configured with a real Mailtrap
        // sandbox account, so the email actually sends; view it at
        // mailtrap.io and click the link.
        // $verified = app()->environment('local');
        $verified = false;

        if ($verified) {
            $user->markEmailAsVerified();
            $user->account_status = 'Active';
            $user->save();
        } else {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'success' => true,
            'verified' => $verified,
            'message' => $verified
                ? 'Registration successful. You can sign in now.'
                : 'Registration successful. Please check your email to verify your account.',
        ], 201);
    }
}
