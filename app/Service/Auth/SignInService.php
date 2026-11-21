<?php

namespace App\Service\Auth;

use App\Enums\AccountStatus;
use App\Http\Resources\UserResource;
use App\Mail\TwoFactorLoginOtpMail;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\SecurityRepository;
use App\Repository\UserRepository;
use App\Service\Concerns\ChecksOtpCodes;
use App\Service\Concerns\SendsOtpMail;
use App\Support\Access\RememberedSession;
use App\Support\Access\UserAgentParser;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Signing in with an email and password, the second factor when the account has one (an
 * authenticator code, or a code sent by email), and signing out. Also who is signed in.
 * Google sign-in is GoogleSignInService; it ends the same way, through issueLoginToken.
 *
 * Split out of App\Service\Auth\UserService, which still hands every call on for its older callers.
 */
class SignInService
{
    use SendsOtpMail, ChecksOtpCodes;

    // Between "password verified" and "token issued" the sign-in waits here, so nothing is issued
    // until the second factor is verified.
    private const TWO_FACTOR_CHALLENGE_PREFIX = 'two_factor_challenge:';
    private const TWO_FACTOR_CHALLENGE_EXPIRY_MINUTES = 10;

    // Wrong codes allowed per challenge before it's thrown away and the
    // user has to re-enter their password — on top of the per-IP route
    // throttle, so a 6-digit code can't be brute-forced within one challenge.
    private const TWO_FACTOR_MAX_ATTEMPTS = 5;
    private const TWO_FACTOR_EMAIL_RESEND_SECONDS = 60;

    // Last accepted TOTP timestamp per user — a code that already signed
    // someone in can't be replayed within its 30-second window.
    private const TWO_FACTOR_TOTP_LAST_PREFIX = 'two_factor_totp_last:';

    public function __construct(
        private UserRepository $userRepository,
        private AuditLogRepository $auditLogRepository,
        private SecurityRepository $securityRepository,
        private Google2FA $google2fa,
    ) {}

    public function getUser(string $uuid)
    {
        $user = $this->userRepository->findByField('uuid', $uuid);
        return new UserResource($user);
    }

    // 2FA is enforced here: a user with two_factor_confirmed_at set gets a
    // short-lived challenge instead of a token (see verifyTwoFactorLogin),
    // same two-step shape as the forget-password flow (email -> OTP ->
    // short-lived reset_token -> real action).
    // Two doors, one lock. The owner door (`$asAdmin` false) refuses an
    // administrator account and the admin door refuses everyone else, each with
    // the same "Invalid email or password." a wrong password gets, so neither
    // door says which emails belong to administrators. The check comes after the
    // password is verified, so the work done is the same either way.
    public function login(object $payload, bool $asAdmin = false)
    {
        if (empty($payload->email) || empty($payload->password)) {
            return response()->json([
                'message' => 'Enter your email or username, and your password.'
            ], 400);
        }

        // The field is called "email" but a staff login may type its username.
        $user = $this->userRepository->findByLogin((string) $payload->email, $this->resolveAudience($payload->input('audience')));

        if (! $user) {
            return response()->json([
                'message' => 'Invalid email or password.'
            ], 401);
        }

        if (! Hash::check($payload->password, $user->password)) {
            $this->auditLogRepository->recordAuthEvent(
                $user->id, 'Login Failed', ['reason' => 'wrong_password'], $payload->ip(), $payload->userAgent(),
            );

            return response()->json([
                'message' => 'Invalid email or password.'
            ], 401);
        }

        if (($user->role === 'system_administrator') !== $asAdmin) {
            $this->auditLogRepository->recordAuthEvent(
                $user->id, 'Login Failed',
                ['reason' => $asAdmin ? 'not_an_administrator' : 'administrator_at_owner_door'],
                $payload->ip(), $payload->userAgent(),
            );

            return response()->json([
                'message' => 'Invalid email or password.'
            ], 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email before logging in.'
            ], 403);
        }

        // Deactivated accounts (e.g. an administrator switched off in
        // Settings → Administrators) can't sign in until reactivated.
        if (in_array($user->account_status, [AccountStatus::Inactive->value, AccountStatus::Suspended->value], true)) {
            return response()->json([
                'message' => 'This account has been deactivated. Contact an administrator to reactivate it.'
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

            $userBusinessUuid = \App\Support\Access\AccountScope::business($user)?->uuid;

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

        if ($user->two_factor_confirmed_at !== null) {
            return $this->twoFactorChallengeResponse($user);
        }

        return response()->json([
            'user' => new UserResource($user),
            'token' => $this->issueLoginToken($user, $payload, 'password'),
        ], 200);
    }

    // Parks a 2FA challenge instead of issuing a token — shared by password
    // login above and Google sign-in below, so neither can skip the second
    // factor on an account that has it enabled.
    public function twoFactorChallengeResponse(User $user): JsonResponse
    {
        $challengeToken = Str::random(64);

        Cache::store('durable')->put(
            self::TWO_FACTOR_CHALLENGE_PREFIX . $challengeToken,
            ['user_id' => $user->id, 'attempts' => 0],
            now()->addMinutes(self::TWO_FACTOR_CHALLENGE_EXPIRY_MINUTES),
        );

        return response()->json([
            'requires_two_factor' => true,
            'challenge_token' => $challengeToken,
            'email' => $user->email,
            // Where the code goes (User::codeEmail): always somewhere for an account with an email.
            'personal_email_available' => $user->codeEmail() !== null,
        ], 200);
    }

    // The one place a login session is created, for both a plain password
    // login and a completed 2FA challenge. The token is named with a short
    // "Browser on OS" label (UserAgentParser) and also keeps the raw
    // IP/User-Agent, so Settings → Login Sessions (SecurityService::sessions)
    // can show the device, and a later remote revocation can log that
    // session's device in Login History rather than the revoker's.
    // $method (password / authenticator / recovery_code / email_code) is
    // recorded on the Login history row.
    //
    // `remember` (the "Remember me" tick; the phone app always sends it) makes
    // it a remembered sign-in: the token gets its own end date a year ahead,
    // pushed forward whenever the device uses it, instead of the 30 days every
    // plain sign-in has. See App\Support\Access\RememberedSession.
    public function issueLoginToken(User $user, Request $request, string $method): string
    {
        $remember = $request->boolean('remember');
        $newToken = $user->createToken(
            UserAgentParser::describe($request->userAgent()),
            ['*'],
            $remember ? RememberedSession::expiresAt(now()) : null,
        );

        $newToken->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ])->save();

        $this->auditLogRepository->recordAuthEvent(
            $user->id,
            'Login',
            ['method' => $method, 'token_id' => $newToken->accessToken->id, 'remember' => $remember],
            $request->ip(),
            $request->userAgent(),
        );

        return $newToken->plainTextToken;
    }

    // Second step of the challenge started above: tries the submitted code
    // against, in order, a TOTP code (never the same one twice), a recovery
    // code (single-use — see SecurityRepository::consumeRecoveryCode), then
    // an emailed OTP if one was requested via requestTwoFactorEmailCode
    // (stored on the same cache entry). Succeeds exactly like a normal login
    // once any one matches; TWO_FACTOR_MAX_ATTEMPTS misses kill the challenge.
    public function verifyTwoFactorLogin(object $payload)
    {
        $key = self::TWO_FACTOR_CHALLENGE_PREFIX . $payload->challenge_token;
        $challenge = Cache::store('durable')->get($key);

        if (! $challenge) {
            return response()->json([
                'message' => 'This sign-in attempt has expired. Please sign in again.',
                'challenge_expired' => true,
            ], 422);
        }

        $user = $this->userRepository->findByField('id', $challenge['user_id']);
        $code = trim((string) $payload->code);
        $method = null;

        // Recovery codes contain a dash; TOTP and emailed codes are 6 digits.
        // Only a 6-digit code is tried as TOTP, so a recovery code attempt
        // never burns a TOTP replay slot (and vice versa).
        $isNumericCode = preg_match('/^\d{6}$/', $code) === 1;

        if ($isNumericCode && $user->two_factor_secret) {
            $lastKey = self::TWO_FACTOR_TOTP_LAST_PREFIX . $user->id;
            $timestamp = $this->google2fa->verifyKeyNewer(
                $user->two_factor_secret,
                $code,
                (int) Cache::store('durable')->get($lastKey, 0),
            );

            if ($timestamp !== false) {
                Cache::store('durable')->put($lastKey, $timestamp, now()->addMinutes(5));
                $method = 'authenticator';
            }
        }

        if (! $method && ! $isNumericCode && $this->securityRepository->consumeRecoveryCode($user, strtoupper($code))) {
            $method = 'recovery_code';
        }

        if (
            ! $method
            && $isNumericCode
            && ! empty($challenge['email_otp_hash'])
            && ! Carbon::parse($challenge['email_otp_created_at'])->addMinutes(self::OTP_EXPIRY_MINUTES)->isPast()
            && Hash::check($code, $challenge['email_otp_hash'])
        ) {
            $method = 'email_code';
        }

        if (! $method) {
            $this->auditLogRepository->recordAuthEvent(
                $user->id, 'Login Failed', ['reason' => 'invalid_two_factor_code'], $payload->ip(), $payload->userAgent(),
            );

            $challenge['attempts'] = ($challenge['attempts'] ?? 0) + 1;

            if ($challenge['attempts'] >= self::TWO_FACTOR_MAX_ATTEMPTS) {
                Cache::store('durable')->forget($key);

                return response()->json([
                    'message' => 'Too many incorrect codes. Please sign in again.',
                    'challenge_expired' => true,
                ], 422);
            }

            // Cache::put resets the TTL — fine, since attempts are capped.
            Cache::store('durable')->put($key, $challenge, now()->addMinutes(self::TWO_FACTOR_CHALLENGE_EXPIRY_MINUTES));

            $remaining = self::TWO_FACTOR_MAX_ATTEMPTS - $challenge['attempts'];

            return response()->json([
                'message' => "Invalid or expired code. {$remaining} " . Str::plural('attempt', $remaining) . ' left.',
                'attempts_remaining' => $remaining,
            ], 422);
        }

        Cache::store('durable')->forget($key);

        $response = [
            'user' => new UserResource($user),
            'token' => $this->issueLoginToken($user, $payload, $method),
        ];

        // Lets the frontend warn "only N recovery codes left" right after
        // one is spent — they're single-use and easy to run out of.
        if ($method === 'recovery_code') {
            $response['recovery_codes_remaining'] = $this->securityRepository->recoveryCodesRemaining($user);
        }

        return response()->json($response, 200);
    }

    // Alternative to a TOTP/recovery code during the challenge above — only
    // available once the account has a VERIFIED personal email
    // (SecurityService::verifyPersonalEmail). The OTP is stashed on the same
    // cache entry the challenge_token already points at, not a new one, so
    // verifyTwoFactorLogin has a single place to check.
    public function requestTwoFactorEmailCode(object $payload)
    {
        $key = self::TWO_FACTOR_CHALLENGE_PREFIX . $payload->challenge_token;
        $challenge = Cache::store('durable')->get($key);

        if (! $challenge) {
            return response()->json([
                'message' => 'This sign-in attempt has expired. Please sign in again.',
                'challenge_expired' => true,
            ], 422);
        }

        $user = $this->userRepository->findByField('id', $challenge['user_id']);

        $to = $user->codeEmail();
        if ($to === null) {
            return response()->json([
                'message' => 'No email on file for this account.',
            ], 422);
        }

        if (! empty($challenge['email_otp_created_at'])) {
            $waitSeconds = self::TWO_FACTOR_EMAIL_RESEND_SECONDS
                - (int) Carbon::parse($challenge['email_otp_created_at'])->diffInSeconds(now());

            if ($waitSeconds > 0) {
                return response()->json([
                    'message' => "Please wait {$waitSeconds} seconds before requesting another code.",
                    'retry_after' => $waitSeconds,
                    'masked_email' => $this->maskEmail($to),
                ], 429);
            }
        }

        $otp = (string) random_int(100000, 999999);
        $challenge['email_otp_hash'] = Hash::make($otp);
        $challenge['email_otp_created_at'] = now()->toISOString();

        Cache::store('durable')->put($key, $challenge, now()->addMinutes(self::TWO_FACTOR_CHALLENGE_EXPIRY_MINUTES));

        $sent = $this->deliver(
            $to,
            new TwoFactorLoginOtpMail($otp, self::OTP_EXPIRY_MINUTES),
            'two-factor login OTP',
        );

        if (! $sent) {
            return response()->json([
                'message' => 'We could not send the code right now. Please try again in a moment.',
            ], 503);
        }

        return response()->json([
            'message' => 'A code has been sent to your email.',
            'masked_email' => $this->maskEmail($to),
            'retry_after' => self::TWO_FACTOR_EMAIL_RESEND_SECONDS,
        ], 200);
    }

    // "etnegaled14@gmail.com" -> "et*********@gmail.com" — enough for the
    // user to recognise which inbox to check, without the unauthenticated
    // challenge page disclosing the full personal address.
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible . str_repeat('*', max(mb_strlen($local) - mb_strlen($visible), 3)) . '@' . $domain;
    }

    public function logoutUser(object $user, ?Request $request = null)
    {
        $token = $user->currentAccessToken();

        if ($token) {
            $this->auditLogRepository->recordAuthEvent(
                $user->id,
                'Logout',
                ['reason' => 'signed_out', 'token_id' => $token->id],
                $request?->ip(),
                $request?->userAgent(),
            );
            $token->delete();
        }

        return response()->json(['message' => 'Logged out successfully'], 200);
    }

    // Which account family a shared-endpoint request (login, password reset)
    // is about. Web (owner-side) is the default so callers that predate the
    // field keep working; the mobile app sends 'mobile' explicitly.
    private function resolveAudience(?string $audience): string
    {
        return $audience === User::AUDIENCE_MOBILE ? User::AUDIENCE_MOBILE : User::AUDIENCE_WEB;
    }
}
