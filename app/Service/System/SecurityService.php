<?php

namespace App\Service\System;

use App\Mail\PersonalEmailVerificationOtpMail;
use App\Mail\TwoFactorSetupOtpMail;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\SecurityRepository;
use App\Service\Concerns\SendsOtpMail;
use App\Support\SessionActivity;
use App\Support\UserAgentParser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Self-service security actions on the CALLER'S OWN account (system
// administrators only — see routes/api.php's system/security group).
// Nothing here manages another admin's account; that's AdminUsersService.
class SecurityService
{
    use SendsOtpMail;

    private const OTP_EXPIRY_MINUTES = 10;
    // How soon another "turn on two-factor" code may be asked for.
    private const TWO_FACTOR_SETUP_RESEND_SECONDS = 60;

    private SecurityRepository $securityRepository;
    private AuditLogRepository $auditLogRepository;

    public function __construct(SecurityRepository $securityRepository, AuditLogRepository $auditLogRepository)
    {
        $this->securityRepository = $securityRepository;
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
    //
    // The second factor is a 6-digit code emailed to the account's VERIFIED
    // personal email each time it signs in (UserService's challenge:
    // requestTwoFactorEmailCode / verifyTwoFactorLogin). There is no
    // authenticator app and there are no recovery codes — so the personal
    // email can't be changed while two-factor is on (submitPersonalEmail),
    // or the account could be left with nowhere to send a code.

    public function twoFactorStatus(User $user)
    {
        $emailReady = $user->personal_email && $user->personal_email_verified_at !== null;

        return response()->json([
            'enabled' => $user->two_factor_confirmed_at !== null,
            'method' => 'email',
            'personal_email_available' => $emailReady,
            'sent_to' => $emailReady ? $user->personal_email : null,
        ], 200);
    }

    // Turning it on, step one: a code goes to the personal email, to prove
    // that inbox can be reached right now. Nothing is on until
    // confirmTwoFactor is given that code. The code is kept where the
    // personal email's own verification code is kept — free, since the
    // email has to be verified already.
    public function enableTwoFactor(User $user)
    {
        if ($user->two_factor_confirmed_at !== null) {
            return response()->json([
                'message' => 'Two-factor authentication is already on.',
            ], 422);
        }

        if (! $user->personal_email || $user->personal_email_verified_at === null) {
            return response()->json([
                'message' => 'Add and verify a personal email first — your sign-in codes are sent there.',
            ], 422);
        }

        if ($user->personal_email_otp_created_at) {
            $wait = self::TWO_FACTOR_SETUP_RESEND_SECONDS
                - (int) Carbon::parse($user->personal_email_otp_created_at)->diffInSeconds(now());

            if ($wait > 0) {
                return response()->json([
                    'message' => "Please wait {$wait} seconds before asking for another code.",
                    'retry_after' => $wait,
                    'sent_to' => $user->personal_email,
                ], 429);
            }
        }

        $otp = (string) random_int(100000, 999999);
        $this->securityRepository->refreshPersonalEmailOtp($user, Hash::make($otp));

        $sent = $this->deliver(
            $user->personal_email,
            new TwoFactorSetupOtpMail($otp, self::OTP_EXPIRY_MINUTES),
            'two-factor setup OTP',
        );

        if (! $sent) {
            return response()->json([
                'message' => 'We could not send the code right now. Please try again in a moment.',
            ], 503);
        }

        return response()->json([
            'message' => 'A code has been sent to your personal email.',
            'sent_to' => $user->personal_email,
            'retry_after' => self::TWO_FACTOR_SETUP_RESEND_SECONDS,
        ], 200);
    }

    public function confirmTwoFactor(User $user, array $payload)
    {
        if (
            $user->two_factor_confirmed_at !== null
            || $user->personal_email_verified_at === null
            || ! $user->personal_email_otp_hash
        ) {
            return response()->json([
                'message' => 'Ask for a code before confirming one.',
            ], 422);
        }

        if (Carbon::parse($user->personal_email_otp_created_at)->addMinutes(self::OTP_EXPIRY_MINUTES)->isPast()) {
            return response()->json([
                'message' => 'This code has expired. Please ask for a new one.',
            ], 410);
        }

        if (! Hash::check($payload['code'], $user->personal_email_otp_hash)) {
            return response()->json([
                'message' => 'Invalid code. Please try again.',
            ], 422);
        }

        $this->securityRepository->confirmEmailTwoFactor($user);
        $this->logAccountEvent($user, 'Activate', ['two_factor' => 'enabled', 'method' => 'email']);

        return response()->json([
            'message' => 'Two-factor authentication is now on.',
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
            'message' => 'Two-factor authentication has been turned off.',
            'enabled' => false,
        ], 200);
    }

    // ── Personal verification email ─────────────────────────────

    public function submitPersonalEmail(User $user, array $payload)
    {
        // Sign-in codes go to this address: changing it while two-factor is
        // on would leave them going nowhere until the new one is verified.
        if ($user->two_factor_confirmed_at !== null) {
            return response()->json([
                'message' => 'Turn off two-factor authentication before changing this email — your sign-in codes are sent to it.',
            ], 422);
        }

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

    // Only the sessions in use are listed (App\Support\SessionActivity):
    // this one, and any other used in the last half hour. Sign-ins left
    // behind by a closed browser are still valid, so they are counted
    // (idle_count) and "log out everywhere else" ends them — but they are
    // not devices anyone is on.
    public function sessions(User $user, ?int $currentTokenId)
    {
        $now = now();
        $lifetime = config('sanctum.expiration') ? (int) config('sanctum.expiration') : null;

        [$active, $idle] = $this->securityRepository->tokens($user)
            ->reject(fn ($token) => SessionActivity::expired($token->created_at, $token->expires_at, $now, $lifetime))
            ->partition(fn ($token) => $token->id === $currentTokenId
                || SessionActivity::active($token->last_used_at, $token->created_at, $now));

        return response()->json([
            'idle_count' => $idle->count(),
            'active_minutes' => SessionActivity::ACTIVE_MINUTES,
            'data' => $active->sortByDesc(fn ($token) => $token->id === $currentTokenId)->values()->map(fn ($token) => [
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
