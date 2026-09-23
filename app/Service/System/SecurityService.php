<?php

namespace App\Service\System;

use App\Mail\PersonalEmailVerificationOtpMail;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\SecurityRepository;
use App\Service\Concerns\SendsOtpMail;
use App\Support\UserAgentParser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

// Self-service security actions on the CALLER'S OWN account (system
// administrators only — see routes/api.php's system/security group).
// Nothing here manages another admin's account; that's AdminUsersService.
class SecurityService
{
    use SendsOtpMail;

    private const OTP_EXPIRY_MINUTES = 10;
    private const RECOVERY_CODE_COUNT = 8;

    private SecurityRepository $securityRepository;
    private Google2FA $google2fa;
    private AuditLogRepository $auditLogRepository;

    public function __construct(SecurityRepository $securityRepository, Google2FA $google2fa, AuditLogRepository $auditLogRepository)
    {
        $this->securityRepository = $securityRepository;
        $this->google2fa = $google2fa;
        $this->auditLogRepository = $auditLogRepository;
    }

    // ── Password ─────────────────────────────────────────────────

    public function changePassword(User $user, array $payload, ?int $currentTokenId)
    {
        if (! Hash::check($payload['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $this->securityRepository->updatePassword($user, Hash::make($payload['password']));
        $this->logAccountEvent($user, 'Change Password', ['password' => '(changed)']);

        // Softer than the forgot-password flow's full tokens()->delete():
        // the current password was already proven, so only kick out OTHER
        // sessions rather than forcing the device that just made this
        // change to sign in again too.
        $this->endOtherSessions($user, $currentTokenId, 'password_changed');

        return response()->json([
            'message' => 'Password updated. Other signed-in devices have been logged out.',
        ], 200);
    }

    // ── Two-factor authentication ───────────────────────────────

    public function twoFactorStatus(User $user)
    {
        $enabled = $user->two_factor_confirmed_at !== null;

        return response()->json([
            'enabled' => $enabled,
            'recovery_codes_remaining' => $enabled ? $this->securityRepository->recoveryCodesRemaining($user) : 0,
            'personal_email_available' => $user->personal_email_verified_at !== null,
        ], 200);
    }

    // Two-step enable: this only generates and stores a PENDING secret +
    // recovery codes (two_factor_confirmed_at stays null). Nothing is
    // actually "enabled" until confirmTwoFactor verifies the admin captured
    // the secret correctly. Calling this again before confirming overwrites
    // the previous pending attempt.
    public function enableTwoFactor(User $user)
    {
        $secret = $this->google2fa->generateSecretKey();
        $recoveryCodes = $this->generateRecoveryCodes();

        $this->securityRepository->setPendingTwoFactor(
            $user,
            $secret,
            array_map(fn (string $code) => Hash::make($code), $recoveryCodes),
        );

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret),
            'recovery_codes' => $recoveryCodes,
        ], 200);
    }

    public function confirmTwoFactor(User $user, array $payload)
    {
        if (! $user->two_factor_secret) {
            return response()->json([
                'message' => 'Start two-factor setup before confirming a code.',
            ], 422);
        }

        if (! $this->google2fa->verifyKey($user->two_factor_secret, $payload['code'])) {
            return response()->json([
                'message' => 'Invalid code. Please try again.',
            ], 422);
        }

        $this->securityRepository->confirmTwoFactor($user);
        $this->logAccountEvent($user, 'Activate', ['two_factor' => 'enabled']);

        return response()->json([
            'message' => 'Two-factor authentication is now enabled.',
            'enabled' => true,
        ], 200);
    }

    public function disableTwoFactor(User $user, array $payload)
    {
        if (! Hash::check($payload['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $this->securityRepository->clearTwoFactor($user);
        $this->logAccountEvent($user, 'Deactivate', ['two_factor' => 'disabled']);

        return response()->json([
            'message' => 'Two-factor authentication has been disabled.',
            'enabled' => false,
        ], 200);
    }

    public function regenerateRecoveryCodes(User $user, array $payload)
    {
        if (! Hash::check($payload['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        if ($user->two_factor_confirmed_at === null) {
            return response()->json([
                'message' => 'Enable two-factor authentication before generating recovery codes.',
            ], 422);
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $this->securityRepository->setRecoveryCodes(
            $user,
            array_map(fn (string $code) => Hash::make($code), $recoveryCodes),
        );
        $this->logAccountEvent($user, 'Update', ['recovery_codes' => 'regenerated']);

        return response()->json([
            'recovery_codes' => $recoveryCodes,
        ], 200);
    }

    /**
     * @return string[] plaintext codes — only ever returned once, at
     *   generation time. Only the hashes are persisted.
     */
    private function generateRecoveryCodes(): array
    {
        return array_map(
            fn () => Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    // ── Personal verification email ─────────────────────────────

    public function submitPersonalEmail(User $user, array $payload)
    {
        $otp = (string) random_int(100000, 999999);
        $previousEmail = $user->personal_email;

        $this->securityRepository->setPendingPersonalEmail($user, $payload['personal_email'], Hash::make($otp));
        $this->logAccountEvent($user, 'Update', ['personal_email' => $payload['personal_email']], ['personal_email' => $previousEmail]);

        $sent = $this->deliver(
            $payload['personal_email'],
            new PersonalEmailVerificationOtpMail($otp, self::OTP_EXPIRY_MINUTES),
            'personal email verification OTP',
        );

        if (! $sent) {
            return response()->json([
                'message' => 'We could not send the verification code right now. Please try again in a moment.',
            ], 503);
        }

        return response()->json([
            'message' => 'A verification code has been sent to your personal email.',
        ], 200);
    }

    public function resendPersonalEmailOtp(User $user)
    {
        if (! $user->personal_email || $user->personal_email_verified_at !== null) {
            return response()->json([
                'message' => 'There is no pending personal email to verify.',
            ], 422);
        }

        $otp = (string) random_int(100000, 999999);

        $this->securityRepository->refreshPersonalEmailOtp($user, Hash::make($otp));

        $sent = $this->deliver(
            $user->personal_email,
            new PersonalEmailVerificationOtpMail($otp, self::OTP_EXPIRY_MINUTES),
            'personal email verification OTP',
        );

        if (! $sent) {
            return response()->json([
                'message' => 'We could not send the verification code right now. Please try again in a moment.',
            ], 503);
        }

        return response()->json([
            'message' => 'A new verification code has been sent to your personal email.',
        ], 200);
    }

    public function verifyPersonalEmail(User $user, array $payload)
    {
        if (! $user->personal_email || ! $user->personal_email_otp_hash) {
            return response()->json([
                'message' => 'No pending personal email verification found.',
            ], 404);
        }

        if (Carbon::parse($user->personal_email_otp_created_at)->addMinutes(self::OTP_EXPIRY_MINUTES)->isPast()) {
            return response()->json([
                'message' => 'This code has expired. Please request a new one.',
            ], 410);
        }

        if (! Hash::check($payload['otp'], $user->personal_email_otp_hash)) {
            return response()->json([
                'message' => 'Invalid code.',
            ], 422);
        }

        $this->securityRepository->verifyPersonalEmail($user);
        $this->logAccountEvent($user, 'Verify Email', ['personal_email' => $user->personal_email]);

        return response()->json([
            'message' => 'Personal email verified.',
            'personal_email_verified' => true,
        ], 200);
    }

    // ── Login sessions ───────────────────────────────────────────

    public function sessions(User $user, ?int $currentTokenId)
    {
        $tokens = $this->securityRepository->tokens($user);

        return response()->json([
            'data' => $tokens->map(fn ($token) => [
                'id' => $token->id,
                // Tokens issued before the user_agent column existed only
                // have their name (already a parsed label, or a raw UA from
                // even earlier) — describe() handles both.
                'label' => $token->user_agent ? UserAgentParser::describe($token->user_agent) : $token->name,
                'device_type' => UserAgentParser::deviceType($token->user_agent ?? $token->name),
                'ip_address' => $token->ip_address,
                'last_used_at' => $token->last_used_at,
                'created_at' => $token->created_at,
                'is_current' => $token->id === $currentTokenId,
            ]),
        ], 200);
    }

    // My own Login / Logout / Login Failed history. Writers: UserService
    // (login, 2FA challenge, logout, password reset) and this class
    // (revoked sessions, password change).
    public function loginHistory(User $user, ?int $currentTokenId)
    {
        $rows = $this->auditLogRepository->loginHistoryForUser($user->id);

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'action' => $row->action,
                'device' => UserAgentParser::describe($row->user_agent),
                'device_type' => UserAgentParser::deviceType($row->user_agent),
                'ip_address' => $row->ip_address,
                'method' => $row->new_values['method'] ?? null,
                'reason' => $row->new_values['reason'] ?? null,
                'is_current_session' => $row->action === 'Login'
                    && $currentTokenId !== null
                    && ($row->new_values['token_id'] ?? null) === $currentTokenId,
                'created_at' => $row->created_at,
            ]),
        ], 200);
    }

    public function revokeSession(User $user, int $tokenId, ?int $currentTokenId)
    {
        if ($tokenId === $currentTokenId) {
            return response()->json([
                'message' => 'You can\'t log out your current session from here.',
            ], 422);
        }

        $token = $this->securityRepository->findToken($user, $tokenId);

        if (! $token) {
            return response()->json([
                'message' => 'Session not found.',
            ], 404);
        }

        $this->auditLogRepository->recordSessionEnded($user->id, $token, 'revoked');
        $this->securityRepository->revokeToken($user, $tokenId);

        return response()->json([
            'message' => 'Session logged out.',
        ], 200);
    }

    public function revokeOtherSessions(User $user, ?int $currentTokenId)
    {
        $count = $this->endOtherSessions($user, $currentTokenId, 'revoked');

        return response()->json([
            'message' => $count > 0
                ? "Logged out {$count} other " . Str::plural('session', $count) . '.'
                : 'No other sessions to log out.',
            'revoked' => $count,
        ], 200);
    }

    // Security change on the admin's OWN account, for Settings > Audit Logs
    // (sign-ins/sign-outs are logged by UserService/endOtherSessions).
    private function logAccountEvent(User $user, string $action, array $new, ?array $old = null): void
    {
        $this->auditLogRepository->record($user->id, 'users', $user->id, $action, $old, $new, request());
    }

    // Deletes every session except the current one, leaving a Logout
    // history row (with $reason) for each so they don't just vanish.
    private function endOtherSessions(User $user, ?int $currentTokenId, string $reason): int
    {
        $tokens = $this->securityRepository->otherTokens($user, $currentTokenId);

        foreach ($tokens as $token) {
            $this->auditLogRepository->recordSessionEnded($user->id, $token, $reason);
        }

        $this->securityRepository->revokeOtherTokens($user, $currentTokenId);

        return $tokens->count();
    }
}
