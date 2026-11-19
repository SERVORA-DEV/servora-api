<?php

use App\Http\Controllers\System\NotificationController;
use App\Http\Controllers\System\ProfilePhotoController;
use App\Http\Controllers\System\SecurityController;
use Illuminate\Support\Facades\Route;

// The signed-in administrator's own account. Open to every admin: no permission flags.

Route::controller(ProfilePhotoController::class)->middleware('throttle:profile-photo')->group(function () {
    Route::post('profile/photo', 'store');
    Route::delete('profile/photo', 'destroy');
});

// Their notification feed (the bell, and Settings → Notifications).
Route::controller(NotificationController::class)->prefix('notifications')->group(function () {
    Route::get('/', 'index');
    Route::post('{id}/read', 'markRead');
    Route::post('read-all', 'markAllRead');
    // "read" before "{id}", so it is never taken for an id.
    Route::delete('read', 'clearRead');
    Route::delete('{id}', 'destroy');
    Route::get('{id}', 'show');
});

// Settings → Security (SecurityService). Not to be confused with admin/user-management,
// which manages other administrators.
Route::controller(SecurityController::class)->prefix('security')->group(function () {
    Route::post('change-password', 'changePassword');

    Route::get('two-factor', 'twoFactorStatus');
    Route::post('two-factor/enable', 'enableTwoFactor')->middleware('throttle:two-factor-send');
    Route::post('two-factor/confirm', 'confirmTwoFactor')->middleware('throttle:two-factor-code');
    Route::post('two-factor/disable', 'disableTwoFactor');

    Route::post('personal-email', 'submitPersonalEmail');
    Route::post('personal-email/verify', 'verifyPersonalEmail')->middleware('throttle:personal-email-code');
    Route::post('personal-email/resend', 'resendPersonalEmailOtp')->middleware('throttle:personal-email-send');

    Route::get('login-history', 'loginHistory');

    Route::get('sessions', 'sessions');
    // Every session except the caller's own.
    Route::delete('sessions', 'revokeOtherSessions');
    // {id} is Sanctum's own numeric token id, the one id here that is not a uuid.
    Route::delete('sessions/{id}', 'revokeSession');
});
