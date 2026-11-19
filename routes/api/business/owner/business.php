<?php

use App\Http\Controllers\Business\BusinessSettingsController;
use App\Http\Controllers\Business\RefundPolicyController;
use App\Http\Controllers\Business\SpaBusinessController;
use App\Http\Controllers\ContactEmailController;
use App\Http\Controllers\ServoraReviewController;
use Illuminate\Support\Facades\Route;

// The owner's business: its settings, refund policies, and their review of SERVORA.

Route::get('/me', [SpaBusinessController::class, 'me']);

// Business Settings: company-wide configuration. Identity is POST because it carries the logo
// as multipart.
Route::prefix('settings')->group(function () {
    Route::controller(BusinessSettingsController::class)->group(function () {
        Route::post('identity', 'updateIdentity');
        Route::patch('legal', 'updateLegal');
        Route::patch('payments', 'updatePayments');
        Route::patch('staff-policy', 'updateStaffPolicy');
        Route::patch('booking-defaults', 'updateBookingDefaults');
        Route::patch('notifications', 'updateNotifications');
    });
    Route::post('identity/email/resend', [ContactEmailController::class, 'resendBusiness'])->middleware('throttle:contact-email-resend');
});

// Business Defaults → Refunds (App\Support\Billing\RefundPolicyRule). Everyone who handles bills reads
// them (front-desk/billing.php); only the owner changes them.
Route::controller(RefundPolicyController::class)->prefix('refund-policies')->group(function () {
    Route::post('/', 'store');
    Route::patch('settings', 'settings');
    Route::patch('{uuid}', 'update');
    Route::delete('{uuid}', 'destroy');
});

// The owner's own review of SERVORA (the profile menu's "Rate SERVORA").
Route::controller(ServoraReviewController::class)->group(function () {
    Route::get('servora-review', 'mine');
    Route::put('servora-review', 'save')->middleware('throttle:servora-review');
});
