<?php

use App\Http\Controllers\Business\AccountProfileController;
use App\Http\Controllers\Business\LivePulseController;
use App\Http\Controllers\Business\NotificationController;
use App\Http\Controllers\System\ProfilePhotoController;
use App\Http\Controllers\System\SecurityController;
use Illuminate\Support\Facades\Route;

// What every staff member has for themselves: owner, manager and front desk alike.

// What changed on the open screens. Asked every few seconds, so cheap by design.
Route::get('live/pulse', [LivePulseController::class, 'show']);

// Their own notification feed: the same NotificationService as the admins', for their user.
Route::controller(NotificationController::class)->prefix('notifications')->group(function () {
    Route::get('/', 'index')->middleware('permission:notification_view');
    Route::post('read-all', 'markAllRead');
    Route::post('{id}/read', 'markRead');
    // Deletes every notification already read. Before "{id}", so it isn't taken for one.
    Route::delete('read', 'clearRead');
    Route::delete('{id}', 'destroy');
    Route::get('{id}', 'show')->middleware('permission:notification_view');
});

// Their own account (Profile → Security): the same SecurityService as the admins'
// Settings → Security. Each throttle has its own name, because unnamed ones of one person share
// a counter, and saving a phone number must not use up the tries at turning on two-factor.
Route::prefix('account')->group(function () {
    Route::patch('profile', [AccountProfileController::class, 'update'])->middleware('throttle:profile-update');

    Route::controller(ProfilePhotoController::class)->middleware('throttle:profile-photo')->group(function () {
        Route::post('photo', 'store');
        Route::delete('photo', 'destroy');
    });

    Route::controller(SecurityController::class)->group(function () {
        // The current password must be given, and every other device is signed out.
        Route::post('change-password', 'changePassword')->middleware('throttle:password-change');

        Route::post('two-factor/enable', 'enableTwoFactor')->middleware('throttle:two-factor-send');
        Route::post('two-factor/confirm', 'confirmTwoFactor')->middleware('throttle:two-factor-code');
        Route::post('two-factor/disable', 'disableTwoFactor');

        Route::post('personal-email', 'submitPersonalEmail');
        Route::post('personal-email/verify', 'verifyPersonalEmail')->middleware('throttle:personal-email-code');
        Route::post('personal-email/resend', 'resendPersonalEmailOtp')->middleware('throttle:personal-email-send');

        Route::get('sessions', 'sessions');
        Route::delete('sessions', 'revokeOtherSessions');
        Route::delete('sessions/{id}', 'revokeSession');
    });
});
