<?php

use App\Http\Controllers\Business\BillingAdjustmentController;
use App\Http\Controllers\Business\BillingController;
use App\Http\Controllers\Business\PaymentController;
use App\Http\Controllers\Business\RefundPolicyController;
use Illuminate\Support\Facades\Route;

// Bills and payments. Typing in a discount, refunding and changing a paid bill are the owner's
// and a manager's; the front desk picks from the discounts the owner made, and may void.

Route::controller(BillingController::class)->group(function () {
    Route::get('billings', 'index')->middleware('permission:billing_view');
    // The overview card: a period's bills, money in, still owed, refunds, how it was paid.
    Route::get('billings/summary', 'summary')->middleware('permission:billing_view');
    Route::get('billing/{uuid}', 'show')->middleware('permission:billing_view');
    // The methods the owner accepts (Settings → Payments), for the payment dialog.
    Route::get('payment-options', 'paymentOptions')->middleware('permission:payment_create');

    Route::patch('billing/{uuid}/discount', 'discount')->middleware(['role:business_owner,manager', 'permission:billing_update']);
    // Customer programs at checkout: the client's vouchers and the discount programs.
    Route::get('billing/{uuid}/program-options', 'programOptions')->middleware('permission:reward_view');
    Route::patch('billing/{uuid}/program-discount', 'programDiscount')->middleware(['permission:reward_update', 'plan.feature:reward_access,open']);
    Route::post('billing/{uuid}/refund', 'refund')->middleware(['role:business_owner,manager', 'permission:payment_refund']);
});

Route::controller(PaymentController::class)->group(function () {
    Route::post('billing/{uuid}/payments', 'store')->middleware('permission:payment_create');
    Route::post('payment/{uuid}/void', 'void')->middleware('permission:payment_refund');
});

// Changing a bill after it is paid (a voucher afterwards, a refund): applied at once, or a
// request a manager or the owner answers. Who may do which is checked in
// BillingAdjustmentService (App\Support\Billing\BillAdjustment).
Route::controller(BillingAdjustmentController::class)->group(function () {
    Route::get('billing-adjustments', 'index')->middleware('permission:billing_view');
    Route::middleware(['role:business_owner,manager', 'permission:payment_create'])->group(function () {
        Route::post('billing/{uuid}/adjustments', 'store')->middleware('throttle:bill-adjustment');
        Route::delete('billing-adjustments/{uuid}', 'destroy');
        Route::post('billing-adjustments/{uuid}/approve', 'approve');
        Route::post('billing-adjustments/{uuid}/decline', 'decline');
    });
});

// The owner's refund policies, read by everyone who handles bills. Changed in owner/business.php.
Route::get('refund-policies', [RefundPolicyController::class, 'index'])->middleware('permission:billing_view');
