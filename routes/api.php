<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\WebActivityController;
use App\Http\Controllers\PushSubscriptionController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// The API, by who may call it. This file is only the gates (sign-in, role, a usable business,
// a live plan); each area's routes are in routes/api/.
//
// Order matters in one way only: a literal path ("account/suggest") must be registered before a
// {parameter} path that could take it ("account/{account}"). The spots where that applies say so.
// To see every route: php artisan route:list --path=api

$routes = fn (string $file) => require __DIR__."/api/{$file}.php";

// What an id in an address may look like, for every route at once: a record's uuid, or a plain
// number (notifications, sign-in sessions). Anything else is a 404 before a controller runs, so a
// literal path ("appointment/summary") is never taken for an id, whatever order the routes are in.
foreach (['uuid', 'branchUuid', 'businessUuid', 'staffUuid', 'timeOffUuid', 'closure', 'photo'] as $name) {
    Route::pattern($name, '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}');
}
Route::pattern('id', '[0-9]+');

// Anyone, signed in or not.
$routes('public');
$routes('auth');

Route::middleware('auth:sanctum')->group(function () use ($routes) {
    Route::get('/auth/me', [AuthController::class, 'getUser']);
    Route::delete('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/web-activity', WebActivityController::class)
        ->middleware('role:'.implode(',', User::BUSINESS_WEB_ROLES));

    // Pop-ups for new notifications while Servora isn't open (any role).
    Route::controller(PushSubscriptionController::class)->prefix('push')->group(function () {
        Route::get('config', 'config');
        Route::post('subscriptions', 'store');
        Route::delete('subscriptions', 'destroy');
        // A test pop-up to one's own devices, with what each push service answered.
        Route::post('test', 'test')->middleware('throttle:push-test');
    });

    // The client mobile app.
    Route::prefix('client')->middleware('role:client')->group(fn () => $routes('client'));

    // System administrators: from an allowed IP, with two-factor on.
    Route::prefix('system')->middleware(['admin.ip', 'role:system_administrator', 'admin.2fa'])->group(function () use ($routes) {
        $routes('system/own-account');
        $routes('system/platform');
        $routes('system/businesses');
    });

    // Spa staff. Every change made here is written to the business's Audit Log
    // (RecordBusinessActivity).
    Route::prefix('business')->middleware('activity')->group(function () use ($routes) {
        // Every staff role, outside the business and plan gates below: the notices an owner most
        // needs (verification decided, plan overdue) arrive exactly when those gates block
        // everything else, and a password must always be changeable.
        // Before owner/branches-and-accounts: its account/{account} would take account/sessions.
        Route::middleware('role:business_owner,manager,front_officer')->group(fn () => $routes('business/own-account'));

        // Running the business: the owner and managers.
        // business.usable turns away an account with no business yet and a suspended one (an
        // unverified owner gets through; verification only decides the marketplace).
        // subscribed.business needs a live plan; it acts on the owner only, and must come after
        // business.usable, which finds the business.
        Route::middleware(['role:business_owner,manager', 'business.usable', 'subscribed.business'])->group(function () use ($routes) {
            $routes('business/manage/overview');
            $routes('business/manage/staff');
            $routes('business/manage/attendance');
            $routes('business/manage/catalog');
            $routes('business/manage/branches');
        });

        // The front desk's day, behind the same gates: clients, visits, the queue, bills and
        // programs. Front officers too, each action by its own permission (EnsurePermission).
        Route::middleware(['role:business_owner,manager,front_officer', 'business.usable', 'subscribed.business'])->group(function () use ($routes) {
            $routes('business/front-desk/visits');
            $routes('business/front-desk/services');
            $routes('business/front-desk/billing');
            $routes('business/front-desk/programs');
            $routes('business/front-desk/lookups');
        });

        // Branch Settings: the owner for any branch, a manager for their own.
        Route::middleware('role:business_owner,manager')->group(fn () => $routes('business/branch-settings'));

        // The owner alone. Outside business.usable on purpose: verification and the plan must be
        // reachable before the business is usable.
        Route::middleware('role:business_owner')->group(function () use ($routes) {
            $routes('business/owner/business');
            $routes('business/owner/verification');
            $routes('business/owner/subscription');
            $routes('business/owner/catalog');
            $routes('business/owner/branches-and-accounts');
        });
    });
});
