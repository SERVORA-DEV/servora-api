<?php

namespace App\Service\Auth;

use App\Enums\AccountStatus;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Repository\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "Continue with Google": the owners' site (which may create the owner account the first time)
 * and the client app. Google's token is checked here; the session itself is issued by
 * SignInService, with its second factor, as for a password sign-in.
 *
 * Split out of App\Service\Auth\UserService, which still hands every call on for its older callers.
 */
class GoogleSignInService
{
    public function __construct(
        private UserRepository $userRepository,
        private RegistrationService $registration,
        private SignInService $signIn,
    ) {}

    /**
     * "Continue with Google" on the owner sign-in / sign-up pages. The web app
     * sends the ID token Google gave the browser; nothing in it is trusted
     * until Google confirms it was issued for our client ID.
     *
     * Owners only: a new email becomes a business_owner account with the
     * password they chose on the sign-up page, and no email verification —
     * Google vouches for the address; an existing owner is signed in.
     * Administrators and staff keep password sign-in.
     */
    public function loginWithGoogle(Request $request)
    {
        $clientId = config('services.google.client_id');

        if (empty($clientId)) {
            return response()->json(['message' => 'Google sign-in is not available.'], 503);
        }

        // The client app (audience "mobile") also accepts the extra client IDs.
        $forClient = $request->input('audience') === User::AUDIENCE_MOBILE;
        $audiences = $forClient
            ? array_merge([$clientId], (array) config('services.google.extra_client_ids', []))
            : [$clientId];

        // The web app asks Google for an access token from its own button (a popup that
        // opens straight from the click, so no browser needs to allow Google's embedded
        // sign-in button); the phone app and older web pages send a Google ID token.
        $claims = ! $forClient && $request->filled('access_token')
            ? $this->verifyGoogleAccessToken((string) $request->input('access_token'), $audiences)
            : $this->verifyGoogleIdToken((string) $request->input('credential'), $audiences);

        if ($claims === null) {
            return response()->json([
                'message' => 'We could not verify your Google account. Please try again.',
            ], 401);
        }

        if ($forClient) {
            return $this->googleClientSignIn($claims, $request);
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
                    // The Google account's own email, for the sign-up page to show.
                    'email' => $claims['email'],
                ], 404);
            }

            // The owner still chooses a password for the account (the same rules
            // as signing up with an email); only the email verification is
            // skipped, since Google has already vouched for the address.
            $passwordCheck = \Illuminate\Support\Facades\Validator::make(
                $request->only('password', 'password_confirmation'),
                ['password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()]],
            );
            if ($passwordCheck->fails()) {
                return response()->json([
                    'message' => $passwordCheck->errors()->first('password'),
                    'needs_password' => true,
                    'errors' => $passwordCheck->errors(),
                ], 422);
            }

            $user = $this->registration->createBusinessOwner([
                'email' => $claims['email'],
                'password' => $request->input('password'),
            ] + $this->registration->termsStamp());

            $user->markEmailAsVerified();
            $user->account_status = AccountStatus::Active->value;
            $user->save();

            return response()->json([
                'user' => new UserResource($user->fresh()),
                'token' => $this->signIn->issueLoginToken($user, $request, 'google'),
            ], 201);
        }

        if ($user->role !== 'business_owner') {
            return response()->json([
                'message' => 'Google sign-in is for business owner accounts. Please sign in with your email and password.',
            ], 403);
        }

        if (in_array($user->account_status, [AccountStatus::Inactive->value, AccountStatus::Suspended->value], true)) {
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
            $user->account_status = AccountStatus::Active->value;
            $user->save();
        }

        if ($user->two_factor_confirmed_at !== null) {
            return $this->signIn->twoFactorChallengeResponse($user);
        }

        return response()->json([
            'user' => new UserResource($user),
            'token' => $this->signIn->issueLoginToken($user, $request, 'google'),
        ], 200);
    }

    // "Continue with Google" in the client app: a client (end customer) account
    // by email. One that exists is signed in; a new one is created already
    // verified (Google vouches for the address) when the Terms were agreed to,
    // with the name Google gives. The phone number and the rest of the profile
    // are asked for by the app's own setup afterwards.
    public function googleClientSignIn(array $claims, Request $request)
    {
        $user = $this->userRepository->findByEmail($claims['email'], User::AUDIENCE_MOBILE);

        if (! $user) {
            // A new account: the phone is asked for the agreement and a password
            // (needs_signup), and calls again with them.
            if (! $request->boolean('terms_accepted')) {
                return response()->json([
                    'message' => 'Please agree to the User Agreement and Privacy Policy to create your account with Google.',
                    'needs_signup' => true,
                    'email' => $claims['email'],
                ], 404);
            }

            // The client still chooses a password for the account (the same rules
            // as signing up with an email); only the email code is skipped,
            // since Google has already vouched for the address.
            $passwordCheck = \Illuminate\Support\Facades\Validator::make(
                $request->only('password', 'password_confirmation'),
                ['password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()]],
            );
            if ($passwordCheck->fails()) {
                return response()->json([
                    'message' => $passwordCheck->errors()->first('password'),
                    'needs_password' => true,
                    'errors' => $passwordCheck->errors(),
                ], 422);
            }

            $user = $this->userRepository->create([
                'role' => 'client',
                'email' => $claims['email'],
                'password' => $request->input('password'),
                'first_name' => $this->googleName($claims['given_name'] ?? null),
                'last_name' => $this->googleName($claims['family_name'] ?? null),
            ] + $this->registration->termsStamp());

            $user->markEmailAsVerified();
            $user->account_status = AccountStatus::Active->value;
            $user->save();

            return response()->json([
                'user' => new UserResource($user->fresh()),
                'token' => $this->signIn->issueLoginToken($user, $request, 'google'),
            ], 201);
        }

        if ($user->role !== 'client') {
            return response()->json([
                'message' => 'This email is not a client account. Please sign in with your email and password.',
            ], 403);
        }

        if (in_array($user->account_status, [AccountStatus::Inactive->value, AccountStatus::Suspended->value], true)) {
            return response()->json([
                'message' => 'This account has been deactivated. Contact support to reactivate it.'
            ], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            // Registered with a password but never proved the address; Google
            // just did, so the unproven password is thrown away.
            $user->password = Str::random(40);
            $user->markEmailAsVerified();
            $user->account_status = AccountStatus::Active->value;
            $user->save();
        }

        return response()->json([
            'user' => new UserResource($user),
            'token' => $this->signIn->issueLoginToken($user, $request, 'google'),
        ], 200);
    }

    private function googleName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : mb_substr($name, 0, 100);
    }

    // Asks Google whether the ID token is genuine, then checks it was issued
    // for one of these apps, by Google, for a Google-verified email, and
    // hasn't expired. Returns the claims, or null on any doubt.
    //
    // @param  string[]  $clientIds  the OAuth client IDs whose tokens are accepted
    private function verifyGoogleIdToken(string $idToken, array $clientIds): ?array
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
            || ! in_array($claims['aud'] ?? null, $clientIds, true)
            || ! in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            || ! in_array($claims['email_verified'] ?? null, [true, 'true'], true)
            || empty($claims['email'])
            || (int) ($claims['exp'] ?? 0) < time()
        ) {
            return null;
        }

        return $claims;
    }

    // A Google ACCESS token (what the web app's own "Continue with Google" button gets),
    // checked the same way as an ID token above: Google says which OAuth client it was
    // issued to (aud/azp must be ours), that it has not run out, and the verified email
    // behind it (the token was asked for with the email scope).
    private function verifyGoogleAccessToken(string $accessToken, array $clientIds): ?array
    {
        if ($accessToken === '') {
            return null;
        }

        try {
            $response = Http::timeout(8)->get('https://oauth2.googleapis.com/tokeninfo', [
                'access_token' => $accessToken,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Google access token verification request failed', ['exception' => $e->getMessage()]);

            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $claims = $response->json();

        if (! is_array($claims)
            || ! in_array($claims['aud'] ?? ($claims['azp'] ?? null), $clientIds, true)
            || ! in_array($claims['email_verified'] ?? null, [true, 'true'], true)
            || empty($claims['email'])
            || (int) ($claims['expires_in'] ?? 0) <= 0
        ) {
            return null;
        }

        return $claims;
    }
}
