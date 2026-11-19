<?php

use App\Http\Controllers\Business\AddonController;
use App\Http\Controllers\Business\CommissionPlanController;
use App\Http\Controllers\Business\PackageController;
use App\Http\Controllers\Business\ServiceController;
use Illuminate\Support\Facades\Route;

// Adding to and changing the catalog every branch shares, and the commission plans.
// Managers read these (manage/catalog.php, manage/staff.php).

// The administrators' catalog, read only, shown when the owner clicks Add Service. Adopting one
// is a normal POST service carrying source_template_uuid; there is no adopt route.
Route::get('service-templates', [ServiceController::class, 'templates']);

Route::apiResource('service', ServiceController::class)->only(['store', 'update', 'destroy']);
Route::apiResource('addon', AddonController::class)->only(['store', 'update', 'destroy']);
Route::apiResource('commission-plan', CommissionPlanController::class)->only(['store', 'update', 'destroy']);
// Packages need a plan that includes them. Reading the list stays open, so the page can show
// what the plan is missing.
Route::apiResource('package', PackageController::class)->only(['store', 'update', 'destroy'])->middleware('plan.feature:package_access');
