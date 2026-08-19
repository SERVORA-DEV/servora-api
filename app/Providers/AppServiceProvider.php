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
        // inline. Reads the CLOUDINARY_URL env var automatically (Cloudinary\
        // Configuration\Configuration::__construct falls back to getenv()
        // when constructed with no arguments).
        $this->app->singleton(Cloudinary::class, fn () => new Cloudinary());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
