<?php

namespace App\Providers;

use Cloudinary\Cloudinary;
use Illuminate\Support\ServiceProvider;

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
        //
    }
}
