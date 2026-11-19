<?php

use App\Http\Controllers\Business\AddonController;
use App\Http\Controllers\Business\BranchOfferingController;
use App\Http\Controllers\Business\CatalogRequestController;
use App\Http\Controllers\Business\PackageController;
use App\Http\Controllers\Business\ServiceController;
use Illuminate\Support\Facades\Route;

// The catalog every branch shares. Managers read it here; adding, changing and retiring entries
// are the owner's (owner/catalog.php). What a manager controls is their own branch's
// availability and price.

Route::apiResource('service', ServiceController::class)->only(['index', 'show'])->middleware('permission:service_view');
Route::apiResource('package', PackageController::class)->only(['index', 'show'])->middleware('permission:package_view');
// Add-ons: extras put on a service during the visit.
Route::apiResource('addon', AddonController::class)->only(['index', 'show'])->middleware('permission:service_view');

// Which branches offer a service length (a variant) or a package, limited to the caller's own
// branches.
Route::patch('service/variant/{uuid}/branches', [ServiceController::class, 'updateVariantBranches'])->middleware('permission:branch_update');
Route::patch('package/{uuid}/branches', [PackageController::class, 'updateBranches'])->middleware(['permission:branch_update', 'plan.feature:package_access']);
// The same for one branch: every service and package change in one save (Branch Settings →
// Services & Packages).
Route::patch('branch/{uuid}/offerings', [BranchOfferingController::class, 'update'])->middleware('permission:branch_update');

// A manager asking the owner for a service or package the catalog doesn't have. Only a manager
// asks; only the owner answers (CatalogRequestService checks both).
Route::controller(CatalogRequestController::class)->prefix('catalog-requests')->group(function () {
    Route::get('/', 'index')->middleware('permission:service_view');
    Route::post('/', 'store')->middleware(['permission:service_view', 'throttle:manager-request']);
    Route::delete('{uuid}', 'destroy')->middleware('permission:service_view');
    Route::middleware(['role:business_owner', 'permission:service_create'])->group(function () {
        Route::post('{uuid}/added', 'added');
        Route::post('{uuid}/decline', 'decline');
    });
});
