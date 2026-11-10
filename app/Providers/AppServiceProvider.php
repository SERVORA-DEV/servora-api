<?php

namespace App\Providers;

use Cloudinary\Cloudinary;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Single shared instance, matching this codebase's convention of
        // constructor-injecting services/repositories rather than `new X()`
        // inline. The URL is passed explicitly from config/services.php —
        // constructing with no arguments makes the SDK fall back to its own
        // getenv('CLOUDINARY_URL'), which skips Laravel's env handling and
        // can't be overridden per-environment.
        $this->app->singleton(
            Cloudinary::class,
            fn () => new Cloudinary(config('services.cloudinary.url')),
        );

        // What one business request changed, for its Audit Log row
        // (RecordBusinessActivity).
        $this->app->singleton(\App\Support\ActivityRecorder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keeps AppCache (settings, subscriptions, permissions, public spa
        // pages) in step with writes.
        \App\Support\CacheInvalidation::register();

        // Tells open screens that something changed at a business.
        \App\Support\LivePulse::register();

        // Notes every model saved during a business request (Audit Log).
        \App\Support\ActivityRecorder::register();

        // Skips Sanctum's last_used_at UPDATE when it was touched recently.
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        // "Remember me" sign-ins (App\Support\RememberedSession): their own
        // end date decides, not the 30-day lifetime of a plain sign-in, and
        // each day the device is used moves that date a year ahead again.
        // Plain sign-ins are left exactly as Sanctum judged them.
        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(function ($token, bool $isValid) {
            if (! \App\Support\RememberedSession::isRemembered($token->expires_at)) {
                return $isValid;
            }

            $now = now();
            if (! \App\Support\RememberedSession::valid($token->expires_at, $now) || $token->tokenable === null) {
                return false;
            }

            if (\App\Support\RememberedSession::shouldExtend($token->expires_at, $now)) {
                $token->forceFill([
                    'expires_at' => \App\Support\RememberedSession::expiresAt($now),
                    'last_used_at' => $now,
                ])->save();
            }

            return true;
        });

        // Site-wide default for every password rule (Password::defaults()).
        Password::defaults(fn () => Password::min(10)->mixedCase()->numbers());

        // Baseline for every /api route (bootstrap/app.php → throttleApi()).
        // Generous enough for dashboards that poll; keyed per user when
        // authenticated, otherwise per IP.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id ?: $r->ip()));

        // Credential stuffing / brute force: cap per IP+email and per IP.
        RateLimiter::for('auth-login', fn (Request $r) => [
            Limit::perMinute(5)->by('login:'.strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(20)->by('login-ip:'.$r->ip()),
        ]);

        // The administrators' own door: much tighter than the owner one. Few
        // people ever sign in here, so five tries a minute per address and a
        // small number per IP per hour is plenty.
        RateLimiter::for('admin-login', fn (Request $r) => [
            Limit::perMinute(5)->by('admin-login:'.strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perHour(30)->by('admin-login-ip:'.$r->ip()),
        ]);

        // Endpoints that send email/SMS (register, forgot password): stops
        // mail bombing and burning the SMTP quota.
        RateLimiter::for('auth-sensitive', fn (Request $r) => [
            Limit::perMinute(3)->by('sens:'.strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(10)->by('sens-ip:'.$r->ip()),
        ]);

        // OTP verification / password reset: guess protection, in addition
        // to the per-code attempt cap in UserService.
        RateLimiter::for('auth-otp', fn (Request $r) => [
            Limit::perMinute(5)->by('otp:'.strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(20)->by('otp-ip:'.$r->ip()),
        ]);

        // A home visit at the door (ScannerController): the page re-reads the visit every 30 s
        // while it runs. Per link and phone; a wrong code is still stopped by the code's own length.
        RateLimiter::for('scanner-visit', fn (Request $r) => Limit::perMinute(40)->by('door:'.substr((string) $r->route('token'), 0, 12).'|'.$r->ip()));
    }
}
