<?php

namespace App\Service;

use App\Http\Resources\UserResource;
use App\Mail\PasswordResetOtpMail;
use App\Mail\RegistrationOtpMail;
use App\Repository\UserRepository;
use Carbon\Carbon;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

            $userBusinessUuid = $user->staff?->branch?->business?->uuid;

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

        $sent = $this->deliver(
            $user->email,
            new PasswordResetOtpMail($otp, self::OTP_EXPIRY_MINUTES),
            'password reset OTP',
        );

        if (! $sent) {
            return response()->json([
                'message' => 'We could not send the reset code right now. Please try again in a moment.'
            ], 503);
        }

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

        // Local dev used to auto-verify owner accounts to skip email delivery,
        // which meant the verification link was never exercised anywhere.
        // Mail now goes out over real Gmail SMTP, so verification behaves
        // identically in every environment and the link gets tested by use.
        //
        // Same reasoning as deliver(): the user and their permission rows are
        // already committed, so a failed send must not become a 500.
        // /auth/resend-verification is the recovery path.
        $sent = true;

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            $sent = false;

            Log::error('Failed to send email verification link', [
                'email' => $user->email,
                'exception' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            // Explicit flag rather than making the frontend pattern-match the
            // message string — see RegisterForm.vue, which branches its
            // success screen on this. Always false now that nothing
            // auto-verifies; kept so that contract doesn't change.
            'verified' => false,
            'message' => $sent
                ? 'Registration successful. Please check your email to verify your account.'
                : 'Registration successful, but we could not send your verification email. Please use the resend option to try again.',
        ], 201);
    }

    public function registerClientUser(array $payload)
    {
        $existing = $this->userRepository->findByField('email', $payload['email']);

        if ($existing) {
            // RegisterClientRequest only lets a duplicate email through when
            // the existing account is still unverified, so reaching here
            // means this is a resend (their first OTP expired or never
            // arrived), not a real collision — refresh their password and
            // issue a fresh code for the same pending account instead of
            // creating a duplicate.
            $user = $this->userRepository->update($existing, [
                'password' => $payload['password'],
            ]);
        } else {
            $user = $this->userRepository->create(array_merge($payload, ['role' => 'client']));
        }

        // Client (mobile) registration always requires OTP email
        // verification, in every environment — unlike registerBusinessUser,
        // there is no local-env auto-verify shortcut here: the mobile app
        // has a real OTP screen that needs a real code to test against.
        $sent = $this->issueRegistrationOtp($user);

        // Always 201, even when the email didn't go out: the account row is
        // already committed, and the mobile app treats anything other than
        // 201 as a hard signup failure (auth_api.dart), which would strand
        // the user on an account they can't get back to. The resend endpoint
        // is the recovery path, so the message points at it.
        return response()->json([
            'success' => true,
            'message' => $sent
                ? 'Registration successful. Please check your email for a verification code.'
                : 'Registration successful, but we could not send your verification code. Please tap Resend to try again.',
        ], 201);
    }

    public function resendRegistrationOtp(array $payload)
    {
        $user = $this->userRepository->findByField('email', $payload['email']);

        if (! $user) {
            return response()->json([
                'message' => 'No account found with this email.'
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified. You can log in now.'
            ], 200);
        }

        if (! $this->issueRegistrationOtp($user)) {
            // Unlike registration, this is the user explicitly asking for an
            // email — reporting success when nothing was sent just makes them
            // wait for a code that will never arrive.
            return response()->json([
                'message' => 'We could not send the code right now. Please try again in a moment.'
            ], 503);
        }

        return response()->json([
            'message' => 'A new verification code has been sent to your email.'
        ], 200);
    }

    /**
     * Generates a fresh 6-digit code, stores its hash (overwriting any
     * previous one for this email), and emails it — shared by
     * registerClientUser (first send) and resendRegistrationOtp (resend),
     * so there's exactly one place that issues a registration OTP.
     */
    private function issueRegistrationOtp(\App\Models\User $user): bool
    {
        $otp = (string) random_int(100000, 999999);

        DB::table('email_verification_otps')->updateOrInsert(
            ['email' => $user->email],
            [
                'otp' => Hash::make($otp),
                'created_at' => now(),
            ]
        );

        // The row is written before the send and left in place even if the
        // send fails, so a code that did go out (slow SMTP, delayed inbox)
        // still verifies. Callers decide how to surface a failed send.
        return $this->deliver(
            $user->email,
            new RegistrationOtpMail($otp, self::OTP_EXPIRY_MINUTES),
            'registration OTP',
        );
    }

    /**
     * Single place every outbound mail goes through. SMTP is a network call
     * to a third party that can fail for reasons that have nothing to do with
     * the caller — a wrong app password, Gmail's daily cap, a dropped
     * connection — and none of those should surface as a 500 on a request
     * whose database work already succeeded.
     *
     * Never logs the mailable's contents: these carry live OTPs, and
     * storage/logs is not where those belong.
     */
    private function deliver(string $email, Mailable $mail, string $context): bool
    {
        try {
            Mail::to($email)->send($mail);

            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send {$context} email", [
                'email' => $email,
                'mailable' => $mail::class,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function verifyRegistrationOtp(array $payload)
    {
        $user = $this->userRepository->findByField('email', $payload['email']);

        if (! $user) {
            return response()->json([
                'message' => 'No account found with this email.'
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified. You can log in now.'
            ], 200);
        }

        $record = DB::table('email_verification_otps')->where('email', $user->email)->first();

        if (! $record) {
            return response()->json([
                'message' => 'No verification request found for this email.'
            ], 404);
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::OTP_EXPIRY_MINUTES)->isPast()) {
            DB::table('email_verification_otps')->where('email', $user->email)->delete();

            return response()->json([
                'message' => 'This code has expired. Please register again to receive a new one.'
            ], 410);
        }

        if (! Hash::check($payload['otp'], $record->otp)) {
            return response()->json([
                'message' => 'Invalid code.'
            ], 422);
        }

        $user->markEmailAsVerified();
        $user->account_status = 'Active';
        $user->save();

        DB::table('email_verification_otps')->where('email', $user->email)->delete();

        return response()->json([
            'message' => 'Email verified successfully. You can now log in.'
        ], 200);
    }
}
