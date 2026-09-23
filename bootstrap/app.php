<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'verified.business' => \App\Http\Middleware\EnsureBusinessVerified::class,
            'subscribed.business' => \App\Http\Middleware\EnsureBusinessSubscribed::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);
    })
    // Requires a real OS cron / Windows Task Scheduler entry running
    // `php artisan schedule:run` every minute in the actual deployment — an
    // ops step outside this codebase. Nothing else in this app runs
    // scheduled tasks yet, so this is the app's first.
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('subscriptions:notify-almost-due')->daily();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Routing\Exceptions\InvalidSignatureException $e, \Illuminate\Http\Request $request) {
            if ($request->route() && $request->route()->named('verification.verify')) {
                return redirect(config('app.frontend_url') . '/verify-email?status=invalid');
            }
        });
    })->create();
