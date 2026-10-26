<?php

namespace App\Support;

use App\Mail\RegistrationLinkMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

// A business owner signing up with an email and a password has no account until
// the email is confirmed. What they typed (the email, the password already
// hashed, when they agreed to the Terms) waits here, and the account is created
// when the link in the email is pressed. Nothing is written to the users table
// before that, so an address nobody confirmed never becomes an account.
//
// Kept in the durable cache, so it needs no table and disappears by itself after
// a day. Signing up again with the same address replaces what was waiting (and
// the old link stops working).
class PendingRegistration
{
    public const HOURS = 24;

    private const BY_TOKEN = 'pending_registration:';
    private const BY_EMAIL = 'pending_registration_email:';
    private const DONE = 'pending_registration_done:';
    private const WATCH = 'pending_registration_watch:';

    private static function store()
    {
        return Cache::store('durable');
    }

    private static function emailKey(string $email): string
    {
        return self::BY_EMAIL . sha1(ContactEmail::normalize($email));
    }

    /**
     * Holds the sign-up and sends the email with the button.
     *
     * @param  array{email: string, password: string}  $fields  the password as typed
     * @param  array<string, mixed>  $terms  terms_accepted_at / terms_version
     * @return array{token: string, watch: string, sent: bool}
     */
    public static function start(array $fields, array $terms): array
    {
        $email = ContactEmail::normalize($fields['email']);

        // A second sign-up with the same address replaces the first.
        if ($old = self::store()->get(self::emailKey($email))) {
            self::store()->forget(self::BY_TOKEN . $old);
        }

        $token = Str::random(48);
        self::store()->put(self::BY_TOKEN . $token, [
            'email' => $email,
            'password' => Hash::make($fields['password']),
            'terms' => $terms,
        ], now()->addHours(self::HOURS));
        self::store()->put(self::emailKey($email), $token, now()->addHours(self::HOURS));

        // For the sign-up page, which asks "has the link been pressed?" with this
        // (and never with the address, so it can't be used to find accounts).
        $watch = Str::random(48);
        self::store()->put(self::WATCH . $watch, $token, now()->addHours(self::HOURS));

        return ['token' => $token, 'watch' => $watch, 'sent' => self::send($email, $token)];
    }

    /** Sends the email again for the sign-up waiting on this address. Null when none is waiting. */
    public static function resend(string $email): ?bool
    {
        $token = self::store()->get(self::emailKey($email));
        if (! $token || ! self::store()->has(self::BY_TOKEN . $token)) {
            return null;
        }

        return self::send(ContactEmail::normalize($email), $token);
    }

    private static function send(string $email, string $token): bool
    {
        $url = URL::temporarySignedRoute('registration.complete', now()->addHours(self::HOURS), ['token' => $token]);

        try {
            Mail::to($email)->send(new RegistrationLinkMail($url, self::HOURS));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send the registration link', ['email' => $email, 'exception' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The button was pressed: the account is created now, already verified.
     *
     * @return string 'success' | 'already' | 'invalid'
     */
    public static function complete(string $token, callable $createOwner): string
    {
        $pending = self::store()->get(self::BY_TOKEN . $token);
        if (! $pending) {
            return self::store()->has(self::DONE . $token) ? 'already' : 'invalid';
        }

        // Someone made an account with this address in the meantime.
        if (User::where('email', $pending['email'])->where('audience', User::AUDIENCE_WEB)->exists()) {
            self::forget($token, $pending['email']);

            return 'already';
        }

        $user = $createOwner($pending);
        $user->markEmailAsVerified();
        $user->account_status = 'Active';
        $user->save();

        self::forget($token, $pending['email']);
        self::store()->put(self::DONE . $token, true, now()->addHours(self::HOURS));

        return 'success';
    }

    private static function forget(string $token, string $email): void
    {
        self::store()->forget(self::BY_TOKEN . $token);
        self::store()->forget(self::emailKey($email));
    }

    /** Whether the sign-up this watch token belongs to has been completed (null: not a pending sign-up's token). */
    public static function watching(string $watch): ?bool
    {
        $token = self::store()->get(self::WATCH . $watch);
        if (! $token) {
            return null;
        }

        return self::store()->has(self::DONE . $token);
    }
}
