<?php

namespace App\Support\Access;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Every rate limit of the API, by name, in one place. A route asks for one with
 * `->middleware('throttle:<name>')`.
 *
 * Each name keeps its own counter. An unnamed `throttle:6,1` would not: every unnamed throttle
 * shares one counter per person (or IP), so a spa's staff signing in, or one person saving a
 * photo and then turning on two-factor, could use up each other's tries.
 *
 * Unless said otherwise a limit counts per signed-in person, or per IP when signed out.
 */
final class RateLimits
{
    public static function register(): void
    {
        // Baseline for every /api route (bootstrap/app.php → throttleApi()). Generous enough for
        // dashboards that poll.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id ?: $r->ip()));

        // ── Signing in and signing up ─────────────────────────────────────────
        // Credential stuffing / brute force: per IP and email, and per IP.
        RateLimiter::for('auth-login', fn (Request $r) => [
            Limit::perMinute(5)->by('login:'.strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(20)->by('login-ip:'.$r->ip()),
        ]);
        // The administrators' own door: much tighter. Few people ever sign in here.
        RateLimiter::for('admin-login', fn (Request $r) => [
            Limit::perMinute(5)->by('admin-login:'.strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perHour(30)->by('admin-login-ip:'.$r->ip()),
        ]);
        // Endpoints that send email (register, forgot password): stops mail bombing.
        RateLimiter::for('auth-sensitive', fn (Request $r) => [
            Limit::perMinute(3)->by('sens:'.strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(10)->by('sens-ip:'.$r->ip()),
        ]);
        // Codes and password reset: guess protection, on top of each code's own attempt cap.
        RateLimiter::for('auth-otp', fn (Request $r) => [
            Limit::perMinute(5)->by('otp:'.strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(20)->by('otp-ip:'.$r->ip()),
        ]);
        // "Continue with Google": per IP, as there is no email typed to count by.
        self::perMinute('google-login', 20);
        // The two-factor step of signing in: the code, and sending it by email.
        self::perMinute('login-2fa-verify', 10);
        self::perMinute('login-2fa-email', 5);
        // A new account's emailed code: checking it, and sending it again.
        self::perMinute('registration-code', 10);
        self::perMinute('registration-resend', 6);
        self::perMinute('verification-resend', 6);
        // Polled by the sign-up page until the emailed link is clicked.
        self::perMinute('verification-status', 60);
        // Buttons in emails (finish signing up, confirm a contact email).
        self::perMinute('email-link', 30);

        // ── One's own account ─────────────────────────────────────────────────
        self::perMinute('profile-update', 20);
        self::perMinute('profile-photo', 20);
        self::perMinute('password-change', 10);
        // Turning on two-factor: sending the code, and confirming it.
        self::perMinute('two-factor-send', 6);
        self::perMinute('two-factor-code', 10);
        // The personal email two-factor codes go to: sending its code, and confirming it.
        self::perMinute('personal-email-send', 6);
        self::perMinute('personal-email-code', 10);
        // A client deleting their account (password-confirmed).
        self::perMinute('account-delete', 5);

        // ── The owner's account ───────────────────────────────────────────────
        // A verified owner changing their name or email: the details, the emailed code, confirming it.
        self::perMinute('identity-change', 20);
        self::perMinute('identity-change-code', 6);
        self::perMinute('identity-change-confirm', 10);
        self::perMinute('registration-change', 10);
        self::perMinute('servora-review', 10);
        // Paying for the plan: starting a checkout, adding GCash, the one free trial.
        self::perMinute('checkout', 20);
        self::perMinute('add-gcash', 10);
        self::perMinute('trial', 10);
        // Sending a business's or branch's contact-email confirmation again.
        self::perMinute('contact-email-resend', 6);

        // ── Running the spa ───────────────────────────────────────────────────
        self::perMinute('setup-guide', 30);
        // A manager asking the owner for a catalog entry or a customer program.
        self::perMinute('manager-request', 20);
        // Changing a bill after it was paid.
        self::perMinute('bill-adjustment', 30);

        // ── Open to anyone ────────────────────────────────────────────────────
        // Small public reads (published reviews, the app's newest version).
        self::perMinute('public-read', 60);
        // A branch's scanner link: opening it, and starting a visit by scanning (against guessing).
        self::perMinute('scanner', 60);
        self::perMinute('scanner-start', 20);
        // A home visit at the door: the page re-reads the visit every 30 s while it runs. Per link
        // and phone; a wrong code is still stopped by the code's own length.
        RateLimiter::for('scanner-visit', fn (Request $r) => Limit::perMinute(40)->by('door:'.substr((string) $r->route('token'), 0, 12).'|'.$r->ip()));
    }

    // $max a minute, per signed-in person or per IP. The name keeps the counter its own.
    private static function perMinute(string $name, int $max): void
    {
        RateLimiter::for($name, fn (Request $r) => Limit::perMinute($max)->by((string) ($r->user()?->id ?: $r->ip())));
    }
}
