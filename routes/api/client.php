<?php

use App\Http\Controllers\Client\ClientAccountController;
use App\Http\Controllers\Client\ClientAppointmentController;
use App\Http\Controllers\Client\ClientBookingController;
use App\Http\Controllers\Client\ClientProfileController;
use App\Http\Controllers\Client\ClientRewardsController;
use Illuminate\Support\Facades\Route;

// The client mobile app, signed in (prefix "client", role client).

Route::controller(ClientProfileController::class)->group(function () {
    Route::patch('profile', 'update');
    // Their own picture, shown to the spas they book with.
    Route::post('profile/photo', 'setPhoto')->middleware('throttle:profile-photo');
    Route::delete('profile/photo', 'removePhoto');
    // Their saved home address, offered on home-service bookings.
    Route::put('home-address', 'setHomeAddress');
    Route::delete('home-address', 'clearHomeAddress');
});

Route::controller(ClientAccountController::class)->group(function () {
    Route::post('change-password', 'changePassword');
    // Password-confirmed; anonymizes the login (ClientAccountService).
    Route::delete('account', 'deleteAccount')->middleware('throttle:account-delete');

    Route::get('favorites', 'favorites');
    Route::put('favorites/{branchUuid}', 'addFavorite');
    Route::delete('favorites/{branchUuid}', 'removeFavorite');

    Route::get('transactions', 'transactions');

    Route::get('notifications', 'notifications');
    Route::post('notifications/read-all', 'markAllNotificationsRead');
    Route::patch('notifications/{id}/read', 'markNotificationRead');
});

// Bookings: made, followed, changed and rated.
Route::post('appointments', [ClientAppointmentController::class, 'store']);
Route::controller(ClientBookingController::class)->group(function () {
    Route::get('appointments', 'index');
    Route::get('appointments/{uuid}', 'show');
    Route::post('appointments/{uuid}/cancel', 'cancel');
    Route::patch('appointments/{uuid}/reschedule', 'reschedule');
    Route::get('appointments/{uuid}/queue', 'queue');

    // One rating per branch, changed with PUT.
    Route::post('appointments/{uuid}/review', 'review');
    Route::get('reviews', 'reviews');
    Route::put('reviews/{uuid}', 'updateReview');

    Route::get('spas/{branchUuid}/booking-status', 'bookingStatus');
});

// Customer programs: points, vouchers, discounts and memberships, per spa.
Route::controller(ClientRewardsController::class)->group(function () {
    Route::get('rewards', 'index');
    // Points turned into a voucher.
    Route::post('rewards/redeem', 'redeem');
    Route::get('spas/{branchUuid}/rewards', 'forBranch');
    Route::get('rewards/{businessUuid}/history', 'history');
});
