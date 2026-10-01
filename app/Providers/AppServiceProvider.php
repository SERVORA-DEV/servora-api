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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}
