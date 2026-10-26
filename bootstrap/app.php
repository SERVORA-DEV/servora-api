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
        // Behind a proxy — the host's (Render and the like) or a forwarded
        // port / dev tunnel: without this $request->ip() is the proxy's
        // address (every per-IP rate limit would become one global bucket),
        // and the API would not know it is being reached over https at the
        // public address, so the links it builds would point at
        // http://127.0.0.1. Traffic only arrives through that proxy layer,
        // so trusting all hops is safe.
        $middleware->trustProxies(at: '*');
        $middleware->throttleApi();
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->append(\App\Http\Middleware\ServerTime::class);
        // Plain text only: no emoji in anything that is kept (promo banner excepted).
        $middleware->append(\App\Http\Middleware\RejectEmoji::class);
        // Links handed to the browser follow the address the site was opened
        // at (localhost, LAN, a forwarded port, the hosted domain).
        $middleware->append(\App\Http\Middleware\FrontendOrigin::class);

        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'verified.business' => \App\Http\Middleware\EnsureBusinessVerified::class,
            'subscribed.business' => \App\Http\Middleware\EnsureBusinessSubscribed::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'plan.feature' => \App\Http\Middleware\EnsurePlanFeature::class,
            'activity' => \App\Http\Middleware\RecordBusinessActivity::class,
        ]);
    })
    // Requires a real OS cron / Windows Task Scheduler entry running
    // `php artisan schedule:run` every minute in the actual deployment — an
    // ops step outside this codebase. Nothing else in this app runs
    // scheduled tasks yet, so this is the app's first.
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('subscriptions:notify-almost-due')->daily();
        $schedule->command('subscriptions:auto-renew')->hourly()->withoutOverlapping();
        $schedule->command('appointments:send-reminders')->everyFiveMinutes();
        $schedule->command('staff:send-front-desk-alerts')->everyFiveMinutes();
        // A service in progress that has run its time (ServiceEndNotifier).
        $schedule->command('appointments:notify-service-ends')->everyMinute()->withoutOverlapping();
        // Bookings and visits a finished day left open (DayCloser).
        $schedule->command('appointments:close-past-days')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('billings:send-payment-reminders')->hourly();
        $schedule->command('programs:daily')->dailyAt('00:10')->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Routing\Exceptions\InvalidSignatureException $e, \Illuminate\Http\Request $request) {
            if ($request->route() && $request->route()->named('verification.verify')) {
                return redirect(config('app.frontend_url') . '/verify-email?status=invalid');
            }
        });
    })->create();
