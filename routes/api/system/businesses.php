<?php

use App\Http\Controllers\ServoraReviewController;
use App\Http\Controllers\System\BranchController;
use App\Http\Controllers\System\BranchRegistrationController;
use App\Http\Controllers\System\BusinessController;
use App\Http\Controllers\System\BusinessRegistrationChangeController;
use App\Http\Controllers\System\OwnerIdentityChangeController;
use App\Http\Controllers\System\OwnerVerificationController;
use Illuminate\Support\Facades\Route;

// Look up spa businesses and branches, and review what owners send in.
// Each route needs the admin's own permission flag (EnsurePermission, AdminPermissions).

// Only an individual branch can be suspended.
Route::controller(BranchController::class)->prefix('branches')->group(function () {
    Route::get('/', 'index')->middleware('permission:branch_view');
    Route::get('{uuid}', 'show')->middleware('permission:branch_view');
    Route::middleware('permission:branch_suspend')->group(function () {
        Route::get('{uuid}/suspension-impact', 'suspensionImpact');
        Route::post('{uuid}/suspend', 'suspend');
    });
});
Route::controller(BusinessController::class)->prefix('businesses')->group(function () {
    Route::middleware('permission:spa_business_view')->group(function () {
        Route::get('/', 'index');
        Route::get('{uuid}', 'show');
        Route::get('{uuid}/programs', 'programs');
    });
});

// A new branch is only reviewed here, never created or edited.
Route::controller(BranchRegistrationController::class)->prefix('branch-registrations')->group(function () {
    Route::get('/', 'index')->middleware('permission:branch_view');
    Route::get('{uuid}', 'show')->middleware('permission:branch_view');
    Route::post('{uuid}/approve', 'approve')->middleware('permission:branch_approve');
    Route::post('{uuid}/reject', 'reject')->middleware('permission:branch_reject');
});

// Owner identity and the business are reviewed separately: one review per account
// (SpaBusiness uuid), with approve, reject and ask-again for each half (OwnerVerificationService).
Route::controller(OwnerVerificationController::class)->prefix('owner-verifications')->group(function () {
    Route::middleware('permission:spa_business_view')->group(function () {
        Route::get('/', 'index');
        Route::get('{uuid}', 'show');
        Route::put('{uuid}/{part}/review-checklist', 'saveChecklist')->whereIn('part', ['identity', 'business']);
    });
    Route::post('{uuid}/identity/request-resubmission', 'requestIdentityResubmission')->middleware('permission:spa_business_reject');
    Route::post('{uuid}/business/request-resubmission', 'requestBusinessResubmission')->middleware('permission:spa_business_reject');

    Route::post('{uuid}/identity/approve', 'approveIdentity')->middleware('permission:spa_business_approve');
    Route::post('{uuid}/identity/reject', 'rejectIdentity')->middleware('permission:spa_business_reject');
    Route::post('{uuid}/business/approve', 'approveBusiness')->middleware('permission:spa_business_approve');
    Route::post('{uuid}/business/reject', 'rejectBusiness')->middleware('permission:spa_business_reject');
});

// A verified owner's new name or email, checked against a new ID and face scan
// (OwnerIdentityChangeService).
Route::controller(OwnerIdentityChangeController::class)->prefix('identity-changes')->group(function () {
    Route::get('/', 'index')->middleware('permission:spa_business_view');
    Route::get('{uuid}', 'show')->middleware('permission:spa_business_view');
    Route::post('{uuid}/approve', 'approve')->middleware('permission:spa_business_approve');
    Route::post('{uuid}/reject', 'reject')->middleware('permission:spa_business_reject');
});

// A verified business's new company registration: DTI number, names, certificate
// (BusinessRegistrationChangeService).
Route::controller(BusinessRegistrationChangeController::class)->prefix('registration-changes')->group(function () {
    Route::get('/', 'index')->middleware('permission:spa_business_view');
    Route::get('{uuid}', 'show')->middleware('permission:spa_business_view');
    Route::post('{uuid}/approve', 'approve')->middleware('permission:spa_business_approve');
    Route::post('{uuid}/reject', 'reject')->middleware('permission:spa_business_reject');
});

// Owners' reviews of SERVORA: published on the website's Reviews page, or hidden.
Route::controller(ServoraReviewController::class)->prefix('servora-reviews')->group(function () {
    Route::get('/', 'adminIndex')->middleware('permission:spa_business_view');
    Route::post('{uuid}/publish', 'publish')->middleware('permission:spa_business_approve');
    Route::post('{uuid}/hide', 'hide')->middleware('permission:spa_business_approve');
});
