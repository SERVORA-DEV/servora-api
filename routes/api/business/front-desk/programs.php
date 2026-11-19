<?php

use App\Http\Controllers\Business\CustomerProgramController;
use App\Http\Controllers\Business\ProgramRequestController;
use Illuminate\Support\Facades\Route;

// Customer programs: loyalty points, vouchers, discounts and memberships.
// Reading is open to every role with reward_view (a manager or the front desk sees what their
// branch offers). Defining programs is the owner's; switching one on or off for a branch is the
// owner's and a manager's; selling memberships is the counter's job too. Any change needs a plan
// that includes rewards.

Route::controller(CustomerProgramController::class)->group(function () {
    Route::middleware('permission:reward_view')->group(function () {
        Route::get('programs', 'index');
        Route::get('programs/memberships', 'memberships');
        Route::get('client/{uuid}/rewards', 'clientRewards');
        Route::get('client/{uuid}/vouchers', 'clientVouchers');
    });

    Route::middleware('plan.feature:reward_access')->group(function () {
        Route::middleware('role:business_owner')->group(function () {
            Route::patch('programs/module', 'module')->middleware('permission:reward_update');
            Route::post('programs', 'store')->middleware('permission:reward_create');
            Route::patch('programs/{uuid}', 'update')->middleware('permission:reward_update');
            Route::delete('programs/{uuid}', 'destroy')->middleware('permission:reward_delete');
        });

        Route::put('programs/{uuid}/branches/{branchUuid}', 'branch')
            ->middleware(['role:business_owner,manager', 'permission:reward_update']);

        Route::middleware('permission:reward_update')->group(function () {
            Route::post('programs/memberships', 'sellMembership');
            Route::post('programs/memberships/{uuid}/renew', 'renewMembership');
            Route::post('programs/memberships/{uuid}/cancel', 'cancelMembership');
        });
    });
});

// A manager asking the owner for a new voucher, discount or membership for their branch. Only a
// manager asks; only the owner answers (ProgramRequestService checks again).
Route::controller(ProgramRequestController::class)->prefix('program-requests')->group(function () {
    Route::get('/', 'index')->middleware(['role:business_owner,manager', 'permission:reward_view']);

    Route::middleware('plan.feature:reward_access')->group(function () {
        Route::post('/', 'store')->middleware(['role:manager', 'permission:reward_view', 'throttle:manager-request']);
        Route::delete('{uuid}', 'destroy')->middleware(['role:business_owner,manager', 'permission:reward_update']);
        Route::middleware(['role:business_owner', 'permission:reward_create'])->group(function () {
            Route::post('{uuid}/approve', 'approve');
            Route::post('{uuid}/decline', 'decline');
        });
    });
});
