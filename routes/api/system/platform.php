<?php

use App\Http\Controllers\System\AdminUsersController;
use App\Http\Controllers\System\AnnouncementController;
use App\Http\Controllers\System\AuditLogController;
use App\Http\Controllers\System\DashboardController;
use App\Http\Controllers\System\RegistrationReportController;
use App\Http\Controllers\System\ServiceTemplateController;
use App\Http\Controllers\System\SubscriberController;
use App\Http\Controllers\System\SubscriptionPlanController;
use App\Http\Controllers\System\SubscriptionReportController;
use App\Http\Controllers\System\SystemSettingController;
use App\Http\Controllers\System\TransactionController;
use Illuminate\Support\Facades\Route;

// Running the platform: plans, administrators, the service catalog, reports and settings.
// Each route needs the admin's own permission flag (EnsurePermission, AdminPermissions).

Route::controller(SubscriptionPlanController::class)->prefix('subscription-plans')->group(function () {
    Route::get('/', 'index')->middleware('permission:subscription_plan_view');
    Route::get('{subscription_plan}', 'show')->middleware('permission:subscription_plan_view');
    Route::post('/', 'store')->middleware('permission:subscription_plan_create');
    Route::match(['put', 'patch'], '{subscription_plan}', 'update')->middleware('permission:subscription_plan_update');
    Route::delete('{subscription_plan}', 'destroy')->middleware('permission:subscription_plan_delete');
});
// Who is subscribed and where each subscription stands (the tab beside Plans).
Route::get('subscribers', [SubscriberController::class, 'index'])->middleware('permission:subscription_plan_view');

// Other administrators' accounts. "Delete" deactivates (AdminUsersService::deactivate).
Route::controller(AdminUsersController::class)->prefix('admin/user-management')->group(function () {
    Route::get('/', 'index')->middleware('permission:admin_view');
    Route::get('{user_management}', 'show')->middleware('permission:admin_view');
    Route::post('/', 'store')->middleware('permission:admin_create');
    Route::match(['put', 'patch'], '{user_management}', 'update')->middleware('permission:admin_update');
    Route::delete('{user_management}', 'destroy')->middleware('permission:admin_delete');
});

// The catalog owners start a new service from: services rows with is_template and no
// business (ServiceTemplateRepository).
Route::controller(ServiceTemplateController::class)->prefix('service-templates')->group(function () {
    Route::get('/', 'index')->middleware('permission:service_template_view');
    Route::get('{uuid}', 'show')->middleware('permission:service_template_view');
    Route::post('/', 'store')->middleware('permission:service_template_create');
    Route::match(['put', 'patch'], '{uuid}', 'update')->middleware('permission:service_template_update');
    Route::delete('{uuid}', 'destroy')->middleware('permission:service_template_delete');
});

Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard_view');

Route::middleware('permission:report_view')->group(function () {
    Route::get('transactions', [TransactionController::class, 'index']);
    Route::get('reports/registrations', RegistrationReportController::class);
    Route::get('reports/subscriptions', SubscriptionReportController::class);
});

// Platform-wide settings (the subscription grace period and the like). Every admin reads them.
Route::controller(SystemSettingController::class)->prefix('settings')->group(function () {
    Route::get('/', 'show');
    Route::post('preview', 'preview')->middleware('permission:setting_update');
    Route::patch('/', 'update')->middleware('permission:setting_update');
});

Route::controller(AuditLogController::class)->middleware('permission:audit_log_view')->group(function () {
    Route::get('audit-logs', 'index');
    // The stat cards, category counts and filter options.
    Route::get('audit-logs/summary', 'summary');
});

// Announcements sent by hand: history, who one would reach, and the recipient lists.
// Kept apart from the automatic notifications.
Route::controller(AnnouncementController::class)->prefix('announcements')->middleware('permission:notification_send')->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::post('preview', 'preview');
    Route::get('recipient-options', 'options');
});
