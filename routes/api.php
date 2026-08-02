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
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\XenditWebhookController;
use App\Http\Controllers\Business\BranchScheduleController;
use App\Http\Controllers\Business\SpaBranchController;
use App\Http\Controllers\System\BranchRegistrationController;

// authentication part
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forget-password', [AuthController::class, 'forgetPassword']);
Route::post('/auth/forget-password/verify-otp', [AuthController::class, 'verifyForgetPasswordOtp']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/business/administrator/register', [RegisterController::class, 'register']);


// display available subscription plan
Route::get('/active-subscription-plans', [SubscriptionPlanController::class, 'displayActivePlans']);


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

    
    // business owner access route
    Route::prefix('business')
        ->middleware('role:business_owner')
        ->group(function () {
            Route::post('/owner/onboarding', [OnboardingController::class, 'store']);

            Route::apiResources([
                'subscription' => SubscriptionController::class,
                'branch' => SpaBranchController::class,
                'branch-schedule' => BranchScheduleController::class
            ]);

            Route::post('branch/{uuid}/registration', [SpaBranchController::class, 'submitRegistration']);

            Route::get('subscription/confirm/{referenceId}', [SubscriptionController::class, 'confirm']);
        });
});


// System Handle Part   

Route::get(
    '/email/verify/{id}/{hash}',
    [EmailVerificationController::class, 'verify']
)->middleware('signed')->name('verification.verify');

// xendit webhook (server-to-server, no auth — verified via x-callback-token)
Route::post('/webhooks/xendit', [XenditWebhookController::class, 'handle']);
