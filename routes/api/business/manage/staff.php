<?php

use App\Http\Controllers\Business\CommissionPlanController;
use App\Http\Controllers\Business\StaffController;
use App\Http\Controllers\Business\StaffEarningsController;
use App\Http\Controllers\Business\StaffScheduleController;
use App\Http\Controllers\Business\StaffTimeOffController;
use App\Http\Controllers\Business\StaffTransferController;
use Illuminate\Support\Facades\Route;

// Employees: who they are, what they may do, when they work, and what they earn.
// Each action needs its own permission, which the owner turns on and off per account.

Route::apiResource('staff', StaffController::class)->only(['index', 'show'])->middleware('permission:staff_view');
Route::apiResource('staff', StaffController::class)->only(['store'])->middleware('permission:staff_create');
Route::apiResource('staff', StaffController::class)->only(['update'])->middleware('permission:staff_update');
Route::apiResource('staff', StaffController::class)->only(['destroy'])->middleware('permission:staff_delete');

Route::controller(StaffController::class)->prefix('staff/{uuid}')->group(function () {
    // The services a therapist may do (AppointmentAvailabilityService::isStaffQualified).
    // PATCH replaces the whole set.
    Route::get('services', 'services')->middleware('permission:staff_view');
    Route::patch('services', 'updateServices')->middleware('permission:staff_update');
    // What they earned between two dates, each commission line.
    Route::get('earnings', 'earnings')->middleware(['role:business_owner,manager', 'permission:staff_view']);
});

// Everyone's earnings for a pay period, less attendance penalties (the Earnings page).
Route::get('earnings', [StaffEarningsController::class, 'index'])->middleware(['role:business_owner,manager', 'permission:commission_view']);
// The commission plans a staff member can be given. Managed by the owner (owner/catalog.php).
Route::get('commission-plan', [CommissionPlanController::class, 'index']);

Route::controller(StaffScheduleController::class)->group(function () {
    // The weekly schedule: hours, days off, breaks. PATCH replaces the whole week. Read by
    // AppointmentAvailabilityService and AttendanceStatusCalculator (late, absent).
    Route::get('staff/{uuid}/schedule', 'show')->middleware('permission:staff_view');
    Route::patch('staff/{uuid}/schedule', 'update')->middleware('permission:staff_update');

    // A manager's schedule change waiting for the owner (Staff Policies → "Owner approval for
    // schedule changes"). Approving and rejecting are the owner's.
    Route::get('schedule-requests', 'requests')->middleware('permission:staff_view');
    Route::middleware(['role:business_owner', 'permission:staff_update'])->group(function () {
        Route::post('schedule-requests/{uuid}/approve', 'approveRequest');
        Route::post('schedule-requests/{uuid}/reject', 'rejectRequest');
    });
    Route::delete('schedule-requests/{uuid}', 'withdrawRequest')->middleware('permission:staff_update');
});

// Time off (leave, sick, absent) by date range, on top of the weekly schedule.
Route::controller(StaffTimeOffController::class)->group(function () {
    // Everyone's, in a date range (the Shifts schedule, the dashboard).
    Route::get('time-off', 'range')->middleware('permission:staff_view');
    Route::get('staff/{uuid}/time-off', 'index')->middleware('permission:staff_view');
    Route::post('staff/{uuid}/time-off', 'store')->middleware('permission:staff_update');
    Route::delete('staff/{uuid}/time-off/{timeOffUuid}', 'destroy')->middleware('permission:staff_update');
});

// Moving someone to another branch, asked for by saving the employee's Branch. The owner's move
// applies at once; a manager's waits here for the owner (StaffTransferService).
Route::controller(StaffTransferController::class)->group(function () {
    // Every branch of the business, which a manager's own branch list is not.
    Route::get('branch-options', 'branchOptions')->middleware('permission:staff_view');
    Route::get('transfer-requests', 'index')->middleware('permission:staff_view');
    Route::middleware(['role:business_owner', 'permission:staff_update'])->group(function () {
        Route::post('transfer-requests/{uuid}/approve', 'approve');
        Route::post('transfer-requests/{uuid}/decline', 'decline');
    });
    Route::delete('transfer-requests/{uuid}', 'withdraw')->middleware('permission:staff_update');
});
