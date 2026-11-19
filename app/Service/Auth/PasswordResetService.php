<?php

namespace App\Service\Auth;

use App\Mail\PasswordResetOtpMail;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Service\Concerns\ChecksOtpCodes;
use App\Service\Concerns\SendsOtpMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Forgot password: a code by email, the code swapped for a short-lived reset token, and the
 * new password set with that token.
 *
 * Split out of App\Service\Auth\UserService, which still hands every call on for its older callers.
 */
class PasswordResetService
{
    use SendsOtpMail, ChecksOtpCodes;

    private const RESET_TOKEN_EXPIRY_MINUTES = 10;

    public function __construct(
        private UserRepository $userRepository,
        private AuditLogRepository $auditLogRepository,
    ) {}

    public function forgetPassword(array $payload)
    {
        $user = $this->userRepository->findByEmail($payload['email'], $this->resolveAudience($payload['audience'] ?? null));

        // Same response whether or not the account exists (or is an admin
        // account that can't self-reset), so this endpoint can't be used to
        // discover which emails are registered.
        $genericResponse = response()->json([
            'message' => 'If an account exists for this email, a one-time password has been sent.'
        ], 200);

        if (! $user || $user->role === 'system_administrator') {
            return $genericResponse;
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email before resetting your password.'
            ], 403);
        }

        $otp = (string) random_int(100000, 999999);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email, 'audience' => $user->audience],
            [
                'token' => Hash::make($otp),
                'created_at' => now(),
                'attempts' => 0,
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

        return $genericResponse;
    }

    public function verifyForgetPasswordOtp(array $payload)
    {
        $user = $this->userRepository->findByEmail($payload['email'], $this->resolveAudience($payload['audience'] ?? null));

        if (! $user) {
            return response()->json(['message' => 'Invalid code.'], 422);
        }

        $record = DB::table('password_reset_tokens')->where('email', $user->email)->where('audience', $user->audience)->first();

        if (! $record) {
            return response()->json(['message' => 'Invalid code.'], 422);
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::OTP_EXPIRY_MINUTES)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $user->email)->where('audience', $user->audience)->delete();

            return response()->json([
                'message' => 'This code has expired. Please request a new one.'
            ], 410);
        }

        if (! Hash::check($payload['otp'], $record->token)) {
            return $this->rejectOtpGuess('password_reset_tokens', $user, (int) $record->attempts);
        }

        $resetToken = Str::random(64);

        DB::table('password_reset_tokens')->where('email', $user->email)->where('audience', $user->audience)->update([
            'token' => Hash::make($resetToken),
            'created_at' => now(),
            'attempts' => 0,
        ]);

        return response()->json([
            'message' => 'Code verified. You may now reset your password.',
            'reset_token' => $resetToken,
        ], 200);
    }

    public function resetPassword(array $payload)
    {
        $user = $this->userRepository->findByEmail($payload['email'], $this->resolveAudience($payload['audience'] ?? null));

        if (! $user) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        $record = DB::table('password_reset_tokens')->where('email', $user->email)->where('audience', $user->audience)->first();

        if (! $record) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        if (! Hash::check($payload['reset_token'], $record->token)) {
            return $this->rejectOtpGuess('password_reset_tokens', $user, (int) $record->attempts, 'Invalid or expired reset token.');
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::RESET_TOKEN_EXPIRY_MINUTES)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $user->email)->where('audience', $user->audience)->delete();

            return response()->json([
                'message' => 'This reset session has expired. Please start again.'
            ], 410);
        }

        $this->userRepository->update($user, [
            'password' => $payload['password'],
        ]);

        DB::table('password_reset_tokens')->where('email', $user->email)->where('audience', $user->audience)->delete();

        foreach ($user->tokens as $token) {
            $this->auditLogRepository->recordSessionEnded($user->id, $token, 'password_reset');
        }

        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password reset successfully. Please sign in with your new password.'
        ], 200);
    }

    // Which account family a shared-endpoint request (login, password reset)
    // is about. Web (owner-side) is the default so callers that predate the
    // field keep working; the mobile app sends 'mobile' explicitly.
    private function resolveAudience(?string $audience): string
    {
        return $audience === User::AUDIENCE_MOBILE ? User::AUDIENCE_MOBILE : User::AUDIENCE_WEB;
    }
}
