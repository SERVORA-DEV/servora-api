<?php

namespace App\Service\Auth;

use App\Enums\AccountStatus;
use App\Mail\RegistrationOtpMail;
use App\Models\User;
use App\Repository\UserRepository;
use App\Service\Concerns\ChecksOtpCodes;
use App\Service\Concerns\SendsOtpMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Making an account: an owner signs up by email and the account is created when the link in
 * the email is pressed; a client signs up in the app and confirms with a code. The owner
 * account itself (createBusinessOwner) is shared with first-time Google sign-in.
 *
 * Split out of App\Service\Auth\UserService, which still hands every call on for its older callers.
 */
class RegistrationService
{
    use SendsOtpMail, ChecksOtpCodes;

    // Lets the sign-up page notice the moment its own verification link is
    // clicked (possibly on another device): registration hands back a random
    // token, and only that token can ask "is this account verified yet?".
    private const VERIFICATION_WATCH_PREFIX = 'verification_watch:';

    public function __construct(
        private UserRepository $userRepository,
    ) {}

    // Creates the owner account and its permission row together — shared by
    // email registration below and first-time Google sign-in.
    public function createBusinessOwner(array $payload): User
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
    public function termsStamp(): array
    {
        return ['terms_accepted_at' => now(), 'terms_version' => config('legal.version')];
    }

    // Signing up with an email: no account is made yet. What was typed waits
    // (App\Support\Access\PendingRegistration) and the account is created when the
    // link in the email is pressed (completeRegistration).
    public function registerBusinessUser(array $payload){

        $pending = \App\Support\Access\PendingRegistration::start($payload, $this->termsStamp());
        $sent = $pending['sent'];

        return response()->json([
            'success' => true,
            // Explicit flag rather than making the frontend pattern-match the
            // message string — see RegisterForm.vue, which branches its
            // success screen on this. Always false now that nothing
            // auto-verifies; kept so that contract doesn't change.
            'verified' => false,
            // For POST /auth/verification-status — see verificationStatus().
            'status_token' => $pending['watch'],
            'message' => $sent
                ? 'Almost there. Open the email we sent and press the button to create your account.'
                : 'We could not send your confirmation email. Please use the resend option to try again.',
        ], 201);
    }

    // The button in the sign-up email was pressed: the account is created now,
    // already verified. Returns 'success' | 'already' | 'invalid'.
    public function completeRegistration(string $token): string
    {
        return \App\Support\Access\PendingRegistration::complete($token, fn (array $pending) => $this->createBusinessOwner([
            'email' => $pending['email'],
            // Already hashed when the sign-up was held.
            'password' => $pending['password'],
        ] + $pending['terms']));
    }

    // Polled by the sign-up page while it shows "check your inbox". Answers
    // only for the token registration issued — never by email — so it can't
    // be used to find out which addresses have accounts. An unknown or
    // expired token just reads as "not verified".
    public function verificationStatus(string $token)
    {
        // A sign-up waiting for its button to be pressed.
        $waiting = \App\Support\Access\PendingRegistration::watching($token);
        if ($waiting !== null) {
            return response()->json(['verified' => $waiting], 200);
        }

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
                'message' => 'Email already verified. You can sign in now.'
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
                'message' => 'Email already verified. You can sign in now.'
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
        $user->account_status = AccountStatus::Active->value;
        $user->save();

        DB::table('email_verification_otps')->where('email', $user->email)->where('audience', $user->audience)->delete();

        return response()->json([
            'message' => 'Email verified successfully. You can now sign in.'
        ], 200);
    }
}
