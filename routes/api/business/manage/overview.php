<?php

use App\Http\Controllers\Business\BusinessAuditLogController;
use App\Http\Controllers\Business\DashboardController;
use App\Http\Controllers\Business\HomeServiceReadinessController;
use App\Http\Controllers\Business\ManagerNotificationPrefsController;
use App\Http\Controllers\Business\PlanAccessController;
use App\Http\Controllers\Business\SetupGuideController;
use Illuminate\Support\Facades\Route;

// The owner's and managers' view of the business as a whole.

Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard_view');

// What the plan allows, and exporting reports.
Route::controller(PlanAccessController::class)->group(function () {
    Route::get('plan-access', 'show');
    Route::get('reports/export-data', 'export')->middleware(['permission:report_view', 'permission:report_export']);
    Route::post('reports/export-authorize', 'authorizeExport')->middleware('permission:report_export');
});

// The setup guide's progress, kept for the whole business.
Route::controller(SetupGuideController::class)->group(function () {
    Route::get('setup-guide', 'show');
    Route::put('setup-guide', 'update')->middleware('throttle:setup-guide');
});

// The home service checklist: what each branch still needs for home visits.
Route::get('home-service/readiness', [HomeServiceReadinessController::class, 'show']);

// The owner's whole business, or the branches a manager runs (BusinessAuditLogService).
Route::get('audit-log', [BusinessAuditLogController::class, 'index'])->middleware('permission:audit_log_view');

// A manager's own switches: which of the owner's changes at their branch they are told about.
Route::controller(ManagerNotificationPrefsController::class)->middleware('role:manager')->group(function () {
    Route::get('notification-preferences', 'show');
    Route::patch('notification-preferences', 'update');
});
