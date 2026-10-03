<?php

namespace App\Service;

use App\Http\Resources\UserResource;
use App\Mail\PasswordResetOtpMail;
use App\Mail\RegistrationOtpMail;
use App\Mail\TwoFactorLoginOtpMail;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\SecurityRepository;
use App\Repository\UserRepository;
use App\Service\Concerns\SendsOtpMail;
use App\Support\UserAgentParser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use PragmaRX\Google2FA\Google2FA;

class UserService
{
    use SendsOtpMail;

    private UserRepository $userRepository;
    private AuditLogRepository $auditLogRepository;
    private SecurityRepository $securityRepository;
    private Google2FA $google2fa;

    private const OTP_EXPIRY_MINUTES = 10;
    private const RESET_TOKEN_EXPIRY_MINUTES = 10;
    // Wrong guesses allowed per OTP / reset token before it is discarded.
    private const MAX_OTP_ATTEMPTS = 5;

    // Parked here, between "password verified" and "token issued", exactly
    // like PENDING_CACHE_PREFIX in SubscriptionService parks a payment
    // intent between "checkout started" and "webhook confirms it" — nothing
    // is issued until the second factor is verified.
    // Lets the sign-up page notice the moment its own verification link is
    // clicked (possibly on another device): registration hands back a random
    // token, and only that token can ask "is this account verified yet?".
    private const VERIFICATION_WATCH_PREFIX = 'verification_watch:';
    private const VERIFICATION_WATCH_HOURS = 24;

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
        UserRepository $userRepository,
        AuditLogRepository $auditLogRepository,
        SecurityRepository $securityRepository,
        Google2FA $google2fa,
    ) {
        $this->userRepository = $userRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->securityRepository = $securityRepository;
        $this->google2fa = $google2fa;
    }

    public function getUser(string $uuid)
    {
        $user = $this->userRepository->findByField('uuid', $uuid);
        return new UserResource($user);
    }

    // 2FA is enforced here: a user with two_factor_confirmed_at set gets a
    // short-lived challenge instead of a token (see verifyTwoFactorLogin),
    // same two-step shape as the forget-password flow (email -> OTP ->
    // short-lived reset_token -> real action).
    public function login(object $payload)
    {
        if (empty($payload->email) || empty($payload->password)) {
            return response()->json([
                'message' => 'Email and password are required'
            ], 400);
        }

        $user = $this->userRepository->findByEmail($payload->email, $this->resolveAudience($payload->input('audience')));

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

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email before logging in.'
            ], 403);
        }

        // Deactivated accounts (e.g. an administrator switched off in
        // Settings → Administrators) can't sign in until reactivated.
        if (in_array($user->account_status, ['Inactive', 'Suspended'], true)) {
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
    private function twoFactorChallengeResponse(User $user): JsonResponse
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
            'personal_email_available' => $user->personal_email_verified_at !== null,
        ], 200);
    }

    /**
     * "Continue with Google" on the owner sign-in / sign-up pages. The web app
     * sends the ID token Google gave the browser; nothing in it is trusted
     * until Google confirms it was issued for our client ID.
     *
     * Owners only: a new email becomes a business_owner account (already
     * verified — Google vouches for the address); an existing owner is signed
     * in. Administrators and staff keep password sign-in.
     */
    public function loginWithGoogle(Request $request)
    {
        $clientId = config('services.google.client_id');

        if (empty($clientId)) {
            return response()->json(['message' => 'Google sign-in is not available.'], 503);
        }

        $claims = $this->verifyGoogleIdToken((string) $request->input('credential'), $clientId);

        if ($claims === null) {
            return response()->json([
                'message' => 'We could not verify your Google account. Please try again.',
            ], 401);
        }

        $user = $this->userRepository->findByEmail($claims['email'], User::AUDIENCE_WEB);

        if (! $user) {
            // A new account needs the Terms agreed to, which only the sign-up
            // page collects. From the sign-in page there's nothing to sign in
            // to yet, so say so instead of quietly creating one.
            if (! $request->boolean('terms_accepted')) {
                return response()->json([
                    'message' => 'There is no Servora account for this Google email yet. Create one from the sign-up page.',
                    'needs_signup' => true,
                ], 404);
            }

            // The password is never shown or used: the owner signs in with
            // Google, and can set a real one through "Forgot password".
            $user = $this->createBusinessOwner([
                'email' => $claims['email'],
                'password' => Str::random(40),
            ] + $this->termsStamp());

            $user->markEmailAsVerified();
            $user->account_status = 'Active';
            $user->save();

            return response()->json([
                'user' => new UserResource($user->fresh()),
                'token' => $this->issueLoginToken($user, $request, 'google'),
            ], 201);
        }

        if ($user->role !== 'business_owner') {
            return response()->json([
                'message' => 'Google sign-in is for business owner accounts. Please sign in with your email and password.',
            ], 403);
        }

        if (in_array($user->account_status, ['Inactive', 'Suspended'], true)) {
            return response()->json([
                'message' => 'This account has been deactivated. Contact an administrator to reactivate it.'
            ], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            // Someone registered this email with a password but never proved
            // they own it. Google just proved who does, so the unverified
            // password is thrown away — otherwise whoever typed it could
            // still sign in to the real owner's account.
            $user->password = Str::random(40);
            $user->markEmailAsVerified();
            $user->account_status = 'Active';
            $user->save();
        }

        if ($user->two_factor_confirmed_at !== null) {
            return $this->twoFactorChallengeResponse($user);
        }

        return response()->json([
            'user' => new UserResource($user),
            'token' => $this->issueLoginToken($user, $request, 'google'),
        ], 200);
    }

    // Asks Google whether the ID token is genuine, then checks it was issued
    // for this app, by Google, for a Google-verified email, and hasn't
    // expired. Returns the claims, or null on any doubt.
    private function verifyGoogleIdToken(string $idToken, string $clientId): ?array
    {
        if ($idToken === '') {
            return null;
        }

        try {
            $response = Http::timeout(8)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Google token verification request failed', ['exception' => $e->getMessage()]);

            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $claims = $response->json();

        if (! is_array($claims)
            || ($claims['aud'] ?? null) !== $clientId
            || ! in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            || ! in_array($claims['email_verified'] ?? null, [true, 'true'], true)
            || empty($claims['email'])
            || (int) ($claims['exp'] ?? 0) < time()
        ) {
            return null;
        }

        return $claims;
    }

    // The one place a login session is created, for both a plain password
    // login and a completed 2FA challenge. The token is named with a short
    // "Browser on OS" label (UserAgentParser) and also keeps the raw
    // IP/User-Agent, so Settings → Login Sessions (SecurityService::sessions)
    // can show the device, and a later remote revocation can log that
    // session's device in Login History rather than the revoker's.
    // $method (password / authenticator / recovery_code / email_code) is
    // recorded on the Login history row.
    private function issueLoginToken(User $user, Request $request, string $method): string
    {
        $newToken = $user->createToken(UserAgentParser::describe($request->userAgent()));

        $newToken->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ])->save();

        $this->auditLogRepository->recordAuthEvent(
            $user->id,
            'Login',
            ['method' => $method, 'token_id' => $newToken->accessToken->id],
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
                'message' => 'This sign-in attempt has expired. Please log in again.',
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
                'message' => 'This sign-in attempt has expired. Please log in again.',
                'challenge_expired' => true,
            ], 422);
        }

        $user = $this->userRepository->findByField('id', $challenge['user_id']);

        if (! $user->personal_email || $user->personal_email_verified_at === null) {
            return response()->json([
                'message' => 'No verified personal email on file for this account.',
            ], 422);
        }

        if (! empty($challenge['email_otp_created_at'])) {
            $waitSeconds = self::TWO_FACTOR_EMAIL_RESEND_SECONDS
                - (int) Carbon::parse($challenge['email_otp_created_at'])->diffInSeconds(now());

            if ($waitSeconds > 0) {
                return response()->json([
                    'message' => "Please wait {$waitSeconds} seconds before requesting another code.",
                    'retry_after' => $waitSeconds,
                    'masked_email' => $this->maskEmail($user->personal_email),
                ], 429);
            }
        }

        $otp = (string) random_int(100000, 999999);
        $challenge['email_otp_hash'] = Hash::make($otp);
        $challenge['email_otp_created_at'] = now()->toISOString();

        Cache::store('durable')->put($key, $challenge, now()->addMinutes(self::TWO_FACTOR_CHALLENGE_EXPIRY_MINUTES));

        $sent = $this->deliver(
            $user->personal_email,
            new TwoFactorLoginOtpMail($otp, self::OTP_EXPIRY_MINUTES),
            'two-factor login OTP',
        );

        if (! $sent) {
            return response()->json([
                'message' => 'We could not send the code right now. Please try again in a moment.',
            ], 503);
        }

        return response()->json([
            'message' => 'A code has been sent to your personal email.',
            'masked_email' => $this->maskEmail($user->personal_email),
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
            'message' => 'Password reset successfully. Please log in with your new password.'
        ], 200);
    }

    /**
     * Records a wrong OTP / reset-token guess against its row and, once the
     * cap is hit, deletes the row so the code can no longer be guessed;
     * the user has to request a fresh one. Shared by the password-reset and
     * registration OTP checks ($table is one of two fixed table names,
     * never user input).
     */
    private function rejectOtpGuess(string $table, User $user, int $attempts, string $message = 'Invalid code.')
    {
        $query = DB::table($table)->where('email', $user->email)->where('audience', $user->audience);

        if ($attempts + 1 >= self::MAX_OTP_ATTEMPTS) {
            $query->delete();

            return response()->json([
                'message' => 'Too many incorrect attempts. Please request a new code.'
            ], 429);
        }

        $query->increment('attempts');

        return response()->json(['message' => $message], 422);
    }

    // Creates the owner account and its permission row together — shared by
    // email registration below and first-time Google sign-in.
    private function createBusinessOwner(array $payload): User
    {
        $payload['role'] = 'business_owner';

        return DB::transaction(function () use ($payload) {
            $user = $this->userRepository->create($payload);

            // Owners get full authority over their own business by default —
            // grant every permission scoped to business_owner (config/permission.php).
            $permissionPayload = array_fill_keys(config('permission.business_owner'), true);
            $permissionPayload['user_id'] = $user->id;
            $this->userRepository->createPermission($permissionPayload);

            return $user;
        });
    }

    // When and which version of the Terms + Privacy Policy a new account
    // agreed to. The request classes have already required the agreement.
    private function termsStamp(): array
    {
        return ['terms_accepted_at' => now(), 'terms_version' => config('legal.version')];
    }

    public function registerBusinessUser(array $payload){

        $user = $this->createBusinessOwner($payload + $this->termsStamp());

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
            // For POST /auth/verification-status — see verificationStatus().
            'status_token' => $this->issueVerificationWatchToken($user),
            'message' => $sent
                ? 'Registration successful. Please check your email to verify your account.'
                : 'Registration successful, but we could not send your verification email. Please use the resend option to try again.',
        ], 201);
    }

    private function issueVerificationWatchToken(User $user): string
    {
        $token = Str::random(48);

        Cache::store('durable')->put(
            self::VERIFICATION_WATCH_PREFIX . $token,
            $user->id,
            now()->addHours(self::VERIFICATION_WATCH_HOURS),
        );

        return $token;
    }

    // Polled by the sign-up page while it shows "check your inbox". Answers
    // only for the token registration issued — never by email — so it can't
    // be used to find out which addresses have accounts. An unknown or
    // expired token just reads as "not verified".
    public function verificationStatus(string $token)
    {
        $key = self::VERIFICATION_WATCH_PREFIX . $token;
        $userId = Cache::store('durable')->get($key);
        $user = $userId ? User::find($userId) : null;
        $verified = (bool) $user?->hasVerifiedEmail();

        if ($verified) {
            Cache::store('durable')->forget($key);
        }

        return response()->json(['verified' => $verified], 200);
    }

    public function registerClientUser(array $payload)
    {
        // terms_accepted was only there to be validated; never a column.
        $existing = $this->userRepository->findByEmail($payload['email'], User::AUDIENCE_MOBILE);

        if ($existing) {
            // RegisterClientRequest only lets a duplicate email through when
            // the existing account is still unverified, so reaching here
            // means this is a resend (their first OTP expired or never
            // arrived), not a real collision — issue a fresh code for the
            // same pending account instead of creating a duplicate.
            //
            // The password is deliberately NOT overwritten: anyone could
            // submit a victim's email here with a password of their own, and
            // if the victim then verified the OTP mailed to them, the
            // attacker would already know the account's password. A user who
            // forgot what they first chose can use "forgot password" once
            // verified.
            $user = $existing;
            $user->update($this->termsStamp());
        } else {
            unset($payload['terms_accepted']);
            $user = $this->userRepository->create(array_merge($payload, ['role' => 'client'], $this->termsStamp()));
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
        $user = $this->userRepository->findByEmail($payload['email'], User::AUDIENCE_MOBILE);

        if (! $user) {
            return response()->json([
                'message' => 'If an account exists for this email, a new verification code has been sent.'
            ], 200);
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
            ['email' => $user->email, 'audience' => $user->audience],
            [
                'otp' => Hash::make($otp),
                'created_at' => now(),
                'attempts' => 0,
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

    public function verifyRegistrationOtp(array $payload)
    {
        $user = $this->userRepository->findByEmail($payload['email'], User::AUDIENCE_MOBILE);

        if (! $user) {
            return response()->json(['message' => 'Invalid code.'], 422);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified. You can log in now.'
            ], 200);
        }

        $record = DB::table('email_verification_otps')->where('email', $user->email)->where('audience', $user->audience)->first();

        if (! $record) {
            return response()->json(['message' => 'Invalid code.'], 422);
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::OTP_EXPIRY_MINUTES)->isPast()) {
            DB::table('email_verification_otps')->where('email', $user->email)->where('audience', $user->audience)->delete();

            return response()->json([
                'message' => 'This code has expired. Please register again to receive a new one.'
            ], 410);
        }

        if (! Hash::check($payload['otp'], $record->otp)) {
            return $this->rejectOtpGuess('email_verification_otps', $user, (int) $record->attempts);
        }

        $user->markEmailAsVerified();
        $user->account_status = 'Active';
        $user->save();

        DB::table('email_verification_otps')->where('email', $user->email)->where('audience', $user->audience)->delete();

        return response()->json([
            'message' => 'Email verified successfully. You can now log in.'
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
