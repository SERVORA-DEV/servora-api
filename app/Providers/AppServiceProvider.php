<?php

namespace App\Providers;

use Cloudinary\Cloudinary;
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
        $this->app->singleton(\App\Support\Activity\ActivityRecorder::class);
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
        \App\Support\Activity\ActivityRecorder::register();

        // Skips Sanctum's last_used_at UPDATE when it was touched recently.
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        // "Remember me" sign-ins (App\Support\Access\RememberedSession): their own
        // end date decides, not the 30-day lifetime of a plain sign-in, and
        // each day the device is used moves that date a year ahead again.
        // Plain sign-ins are left exactly as Sanctum judged them.
        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(function ($token, bool $isValid) {
            if (! \App\Support\Access\RememberedSession::isRemembered($token->expires_at)) {
                return $isValid;
            }

            $now = now();
            if (! \App\Support\Access\RememberedSession::valid($token->expires_at, $now) || $token->tokenable === null) {
                return false;
            }

            if (\App\Support\Access\RememberedSession::shouldExtend($token->expires_at, $now)) {
                $token->forceFill([
                    'expires_at' => \App\Support\Access\RememberedSession::expiresAt($now),
                    'last_used_at' => $now,
                ])->save();
            }

            return true;
        });

        // Site-wide default for every password rule (Password::defaults()).
        Password::defaults(fn () => Password::min(10)->mixedCase()->numbers());

        // Every rate limit of the API, by name (routes use throttle:<name>).
        \App\Support\Access\RateLimits::register();

        // Who may do what to a record: app/Policies, found by name (App\Models\X → App\Policies\XPolicy).
        // Abilities that aren't about one record are named here.
        \Illuminate\Support\Facades\Gate::define('override-booking-rules', [\App\Policies\BookingRulesPolicy::class, 'override']);
    }
}
