<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

// The owner's SERVORA plan: paying for it, renewing it, and changing it.

// Servora's own checkout (card, GCash or Maya through Xendit), auto-renew and saved methods.
Route::controller(CheckoutController::class)->group(function () {
    Route::get('checkout/quote', 'quote');
    Route::post('checkout', 'start')->middleware('throttle:checkout');
    Route::get('checkout/{uuid}', 'status');
    Route::patch('subscription/auto-renew', 'setAutoRenew');

    // GCash is added by its number (Xendit can't save it) and approved each time it pays.
    Route::post('payment-methods/gcash', 'addGcash')->middleware('throttle:add-gcash');
    Route::post('payment-methods/{uuid}/default', 'makeDefaultMethod');
    Route::delete('payment-methods/{uuid}', 'removeMethod');
});

Route::controller(SubscriptionController::class)->prefix('subscription')->group(function () {
    // Only the plan's limits and current usage, for the Plan Capacity card (the full read
    // also settles payments and loads the billing history).
    Route::get('capacity', 'capacity');
    // The one free trial (config/trial.php).
    Route::post('trial', 'startTrial')->middleware('throttle:trial');

    // Upgrading or downgrading the live plan (PlanSwitchService).
    Route::get('change/quote', 'quotePlanChange');
    Route::post('change', 'changePlan');
    Route::delete('change', 'cancelScheduledPlanChange');

    Route::get('confirm/{referenceId}', 'confirm');
    // Accepting or declining an administrator's change to the subscribed plan (PlanChangeService).
    Route::post('plan-change', 'respondToPlanChange');
});

// Owners only read and buy subscriptions: the plan, dates and status change through payments and
// plan switches, never by editing the row.
Route::apiResource('subscription', SubscriptionController::class)->only(['index', 'store']);
