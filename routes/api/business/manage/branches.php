<?php

use App\Http\Controllers\Business\BranchClosureController;
use App\Http\Controllers\Business\BranchScheduleController;
use App\Http\Controllers\Business\FacilityController;
use App\Http\Controllers\Business\SpaBranchController;
use Illuminate\Support\Facades\Route;

// Branches, their rooms and their hours. A manager only ever sees and changes their own branch:
// each service looks the uuid up among the caller's own branches (branchesForUser).

Route::apiResource('branch', SpaBranchController::class)->only(['index', 'show'])->middleware('permission:branch_view');

// Rooms and beds.
Route::apiResource('facility', FacilityController::class)->only(['index', 'show'])->middleware('permission:facility_view');
Route::apiResource('facility', FacilityController::class)->only(['store'])->middleware('permission:facility_create');
Route::apiResource('facility', FacilityController::class)->only(['update'])->middleware('permission:facility_update');
Route::apiResource('facility', FacilityController::class)->only(['destroy'])->middleware('permission:facility_delete');

// Opening hours (BranchScheduleService).
Route::apiResource('branch-schedule', BranchScheduleController::class)->only(['index', 'show'])->middleware('permission:branch_view');
Route::apiResource('branch-schedule', BranchScheduleController::class)->only(['store', 'update', 'destroy'])->middleware('permission:branch_update');
// The whole week at once: what the hours editors save with.
Route::put('branch/{uuid}/schedule', [BranchScheduleController::class, 'saveWeek'])->middleware('permission:branch_update');

// Closed outside the weekly hours: a holiday, repairs (BranchClosureService).
Route::controller(BranchClosureController::class)->prefix('branch/{uuid}/closures')->group(function () {
    Route::get('/', 'index')->middleware('permission:branch_view');
    // The bookings a closure would land on.
    Route::get('conflicts', 'conflicts')->middleware('permission:branch_view');
    Route::post('/', 'store')->middleware('permission:branch_update');
    Route::patch('{closure}', 'update')->middleware('permission:branch_update');
    Route::delete('{closure}', 'destroy')->middleware('permission:branch_update');
});
