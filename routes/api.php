<?php


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\System\SubscriptionPlanController;
use App\Http\Controllers\System\AdminUsersController;
use App\Http\Controllers\System\TransactionController;
use App\Http\Controllers\Owner\OnboardingController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\XenditWebhookController;

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
        });

    
    // business owner access route
    Route::prefix('business')
        ->middleware('role:business_owner')
        ->group(function () {
            Route::post('/owner/onboarding', [OnboardingController::class, 'store']);

            Route::apiResources([
                'subscription' => SubscriptionController::class
            ]);
        });
});


// System Handle Part   

Route::get(
    '/email/verify/{id}/{hash}',
    [EmailVerificationController::class, 'verify']
)->middleware('signed')->name('verification.verify');

// xendit webhook (server-to-server, no auth — verified via x-callback-token)
Route::post('/webhooks/xendit', [XenditWebhookController::class, 'handle']);
