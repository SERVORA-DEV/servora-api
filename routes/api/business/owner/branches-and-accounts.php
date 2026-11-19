<?php

use App\Http\Controllers\Business\AccountController;
use App\Http\Controllers\Business\SpaBranchController;
use Illuminate\Support\Facades\Route;

// Opening and running branches, and the staff logins: the owner's alone.

// Before the resource: its account/{account} would take "suggest" for an id.
Route::get('account/suggest', [AccountController::class, 'suggest']);
Route::apiResource('account', AccountController::class);
// A login a smaller plan put on hold, back once the plan has room (PlanFitService).
Route::post('account/{uuid}/bring-back', [AccountController::class, 'bringBack']);

Route::apiResource('branch', SpaBranchController::class)->except(['index', 'show']);
Route::controller(SpaBranchController::class)->prefix('branch/{uuid}')->group(function () {
    Route::post('clone', 'clone');

    // Registering a branch: where it is, its permit, then sending it for review.
    Route::post('location', 'saveLocation');
    Route::post('permit', 'savePermit');
    Route::post('submit', 'submit');
    Route::post('reverify', 'reverify');

    // A branch a smaller plan put on hold, back once the plan has room (PlanFitService).
    Route::post('bring-back', 'bringBack');
    // The owner's main branch: listed first, opened first.
    Route::post('main', 'makeMain');
});
