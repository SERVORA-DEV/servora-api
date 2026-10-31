<?php

use App\Http\Middleware\EnsureBusinessSubscribed;
use App\Http\Middleware\EnsureBusinessVerified;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\FrontendOrigin;
use App\Http\Middleware\RecordBusinessActivity;
use App\Http\Middleware\RejectEmoji;
use App\Http\Middleware\RequireAdminTwoFactor;
use App\Http\Middleware\RestrictAdminIp;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ServerTime;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;

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
        $middleware->append(SecurityHeaders::class);
        $middleware->append(ServerTime::class);
        // Plain text only: no emoji in anything that is kept (promo banner excepted).
        $middleware->append(RejectEmoji::class);
        // Links handed to the browser follow the address the site was opened
        // at (localhost, LAN, a forwarded port, the hosted domain).
        $middleware->append(FrontendOrigin::class);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'verified.business' => EnsureBusinessVerified::class,
            'subscribed.business' => EnsureBusinessSubscribed::class,
            'permission' => EnsurePermission::class,
            'plan.feature' => EnsurePlanFeature::class,
            'activity' => RecordBusinessActivity::class,
            'admin.ip' => RestrictAdminIp::class,
            'admin.2fa' => RequireAdminTwoFactor::class,
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
        $exceptions->render(function (InvalidArgumentException $e, Request $request) {
            if ($request->is('api/business/subscription/*') || $request->is('api/business/checkout*')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if ($request->route() && $request->route()->named('verification.verify')) {
                return redirect(config('app.frontend_url').'/verify-email?status=invalid');
            }
        });
    })->create();
