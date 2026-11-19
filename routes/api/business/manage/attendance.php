<?php

use App\Http\Controllers\Business\AttendanceController;
use Illuminate\Support\Facades\Route;

// Attendance, run by the owner and managers. The front desk has its own narrower view
// (front-desk/lookups.php).

Route::apiResource('attendance', AttendanceController::class)->only(['index'])->middleware('permission:attendance_view');
Route::apiResource('attendance', AttendanceController::class)->only(['store'])->middleware('permission:attendance_create');
Route::apiResource('attendance', AttendanceController::class)->only(['destroy'])->middleware('permission:attendance_delete');

Route::controller(AttendanceController::class)->prefix('attendance')->group(function () {
    Route::post('bulk-mark-present', 'bulkMarkPresent')->middleware('permission:attendance_create');
    // One day's roster, and the owner's analytics report.
    Route::get('roster', 'roster')->middleware('permission:attendance_view');
    Route::get('report', 'report')->middleware('permission:attendance_view');

    // After every literal attendance/… path above, so none of them is taken for a uuid.
    Route::get('{uuid}', 'show')->middleware('permission:attendance_view');
    Route::patch('{uuid}', 'update')->middleware('permission:attendance_update');
});
