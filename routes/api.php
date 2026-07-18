<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Http;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'getUser']);
    Route::delete('/auth/logout', [AuthController::class, 'logout']); 
});

// display available subscription plan

Route::get('/active-subscription-plans', [SubscriptionPlanController::class, 'displayActivePlans']);


// user register

Route::post('/business/administrator/register', [RegisterController::class, 'register']);

Route::get(
    '/email/verify/{id}/{hash}',
    [EmailVerificationController::class, 'verify']
)->middleware('signed')->name('verification.verify');










Route::get('/payments/test', [PaymentController::class, 'testXendit']);

Route::post('/payments/create', [PaymentController::class, 'createPaymentRequest']);

Route::get('/payments/success', [PaymentController::class, 'returnSuccess']);
Route::get('/payments/failed', [PaymentController::class, 'returnFailed']);