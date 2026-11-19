<?php

use App\Http\Controllers\Business\BranchSettingsController;
use App\Http\Controllers\Business\BusinessSettingsController;
use App\Http\Controllers\ContactEmailController;
use Illuminate\Support\Facades\Route;

// Branch Settings (web /business/settings/branch/*): the owner for any of their branches, a
// manager for their own only. BranchSettingsService looks the uuid up among the caller's own
// branches, so any other branch is a 404.

Route::controller(BranchSettingsController::class)->prefix('branch/{uuid}')->group(function () {
    Route::get('marketplace', 'marketplace')->middleware('permission:branch_view');

    Route::middleware('permission:branch_update')->group(function () {
        Route::patch('marketplace', 'updateMarketplace');
        Route::post('photos', 'uploadPhotos');
        Route::delete('photos/{photo}', 'deletePhoto');
        Route::post('photos/{photo}/cover', 'setCover');
        Route::patch('photos/order', 'reorderPhotos');
        Route::patch('booking-policy', 'updateBookingPolicy');
    });

    Route::get('reviews-summary', 'reviewsSummary')->middleware('permission:review_view');
});

Route::post('branch/{uuid}/email/resend', [ContactEmailController::class, 'resendBranch'])->middleware(['permission:branch_update', 'throttle:contact-email-resend']);

// Business Settings, read side. A manager gets only what a branch inherits (identity and booking
// defaults); every change is the owner's (owner/business.php).
Route::get('settings', [BusinessSettingsController::class, 'show']);
