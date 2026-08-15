<?php


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\System\SubscriptionPlanController;
use App\Http\Controllers\System\AdminUsersController;
use App\Http\Controllers\System\TransactionController;
use App\Http\Controllers\Business\OnboardingController;
use App\Http\Controllers\Business\DashboardController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\XenditWebhookController;
use App\Http\Controllers\Business\BranchScheduleController;
use App\Http\Controllers\Business\SpaBranchController;
use App\Http\Controllers\Business\AccountController;
use App\Http\Controllers\Business\StaffController;
use App\Http\Controllers\Business\FacilityController;
use App\Http\Controllers\Business\ServiceController;
use App\Http\Controllers\Business\PackageController;
use App\Http\Controllers\Business\SpaBusinessController;
use App\Http\Controllers\System\BranchRegistrationController;

// authentication part
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forget-password', [AuthController::class, 'forgetPassword']);
Route::post('/auth/forget-password/verify-otp', [AuthController::class, 'verifyForgetPasswordOtp']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/business/administrator/register', [RegisterController::class, 'register']);


// display available subscription plan
Route::get('/active-subscription-plans', [SubscriptionPlanController::class, 'displayActivePlans']);

// Public, unauthenticated business lookup — backs the branded
// /login/{business_uuid} page on the frontend (see PublicSpaBusinessResource
// for what's exposed here).
Route::get('/business/{uuid}/public', [SpaBusinessController::class, 'publicShow']);


// auth sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'getUser']);
    Route::delete('/auth/logout', [AuthController::class, 'logout']);


    // ssytem administrator access route
    Route::prefix('system')
        ->middleware('role:system_administrator')
        ->group(function () {
            Route::apiResources([
                'subscription-plans' => SubscriptionPlanController::class,
                'admin/user-management' => AdminUsersController::class
            ]);

            Route::get('transactions', [TransactionController::class, 'index']);

            // No store/update/destroy — a registration is only ever
            // reviewed (approve/reject), never created or edited here.
            Route::apiResource('branch-registrations', BranchRegistrationController::class)
                ->parameters(['branch-registrations' => 'uuid'])
                ->only(['index', 'show']);

            Route::post('branch-registrations/{uuid}/approve', [BranchRegistrationController::class, 'approve']);
            Route::post('branch-registrations/{uuid}/reject', [BranchRegistrationController::class, 'reject']);
        });

    
    // business access routes — split by role. Manager shares the owner's
    // dashboard and Employee Management (see ROLE_HOME.manager on the
    // frontend and SpaBusinessRepository::findForUser, which resolves
    // "their" business the same way for both), but not billing, account
    // grants, or branch editing — those stay owner-only.
    Route::prefix('business')
        ->group(function () {
            Route::middleware('role:business_owner,manager')->group(function () {
                Route::apiResource('staff', StaffController::class);
                Route::apiResource('branch', SpaBranchController::class)->only(['index', 'show']);
                Route::apiResource('facility', FacilityController::class);
                Route::get('dashboard', [DashboardController::class, 'index']);

                // Manager can view and edit the catalog (service_view/service_update,
                // package_view/package_update in config/permission.php) but not
                // create or retire entries — those stay owner-only below.
                Route::apiResource('service', ServiceController::class)->only(['index', 'show', 'update']);
                Route::apiResource('package', PackageController::class)->only(['index', 'show', 'update']);

                // Per-branch availability toggle (branch_services/branch_packages) —
                // reuses service_update/package_update rather than a new permission,
                // scoped server-side to the caller's own branches (manager can only
                // ever submit rows for their one AccountBranch-assigned branch).
                Route::patch('service/{uuid}/branches', [ServiceController::class, 'updateBranches']);
                Route::patch('package/{uuid}/branches', [PackageController::class, 'updateBranches']);
            });

            Route::middleware('role:business_owner')->group(function () {
                Route::post('/owner/onboarding', [OnboardingController::class, 'store']);
                Route::get('/me', [SpaBusinessController::class, 'me']);

                Route::apiResources([
                    'subscription' => SubscriptionController::class,
                    'branch-schedule' => BranchScheduleController::class,
                    'account' => AccountController::class,
                ]);
                Route::apiResource('branch', SpaBranchController::class)->except(['index', 'show']);
                Route::apiResource('service', ServiceController::class)->only(['store', 'destroy']);
                Route::apiResource('package', PackageController::class)->only(['store', 'destroy']);

                Route::post('branch/{uuid}/registration', [SpaBranchController::class, 'submitRegistration']);

                Route::get('subscription/confirm/{referenceId}', [SubscriptionController::class, 'confirm']);
            });
        });
});


// System Handle Part   

Route::get(
    '/email/verify/{id}/{hash}',
    [EmailVerificationController::class, 'verify']
)->middleware('signed')->name('verification.verify');

// xendit webhook (server-to-server, no auth — verified via x-callback-token)
Route::post('/webhooks/xendit', [XenditWebhookController::class, 'handle']);
