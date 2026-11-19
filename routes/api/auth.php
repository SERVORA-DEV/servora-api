<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ContactEmailController;
use Illuminate\Support\Facades\Route;

// Signing in, signing up and getting back in. Each door has its own rate limit on top of the
// attempt caps in the Auth services. Throttles that matter apart are named: unnamed ones from one IP
// share a counter, and a spa's staff share an IP.

Route::controller(AuthController::class)->prefix('auth')->group(function () {
    Route::post('login', 'login')->middleware('throttle:auth-login');
    // The only door that takes an administrator's password (config/admin_access.php).
    Route::post('admin/login', 'adminLogin')->middleware(['admin.ip', 'throttle:admin-login']);
    // "Continue with Google" for business owners (GoogleSignInService::loginWithGoogle).
    Route::post('google', 'loginWithGoogle')->middleware('throttle:google-login');

    Route::post('two-factor/verify', 'verifyTwoFactorLogin')->middleware('throttle:login-2fa-verify');
    Route::post('two-factor/request-email-code', 'requestTwoFactorEmailCode')->middleware('throttle:login-2fa-email');

    Route::post('forget-password', 'forgetPassword')->middleware('throttle:auth-sensitive');
    Route::post('forget-password/verify-otp', 'verifyForgetPasswordOtp')->middleware('throttle:auth-otp');
    Route::post('reset-password', 'resetPassword')->middleware('throttle:auth-otp');

    // Polled by the sign-up page, so it moves on the moment the emailed link is clicked.
    Route::post('verification-status', 'verificationStatus')->middleware('throttle:verification-status');
    Route::post('verify-registration-otp', 'verifyRegistrationOtp')->middleware('throttle:registration-code');
    Route::post('resend-registration-otp', 'resendRegistrationOtp')->middleware('throttle:registration-resend');
});

Route::post('/auth/register', [RegisterController::class, 'registerClient'])->middleware('throttle:auth-sensitive');
Route::post('/auth/resend-verification', [EmailVerificationController::class, 'resend'])->middleware('throttle:verification-resend');
Route::post('/business/administrator/register', [RegisterController::class, 'register'])->middleware('throttle:auth-sensitive');

// Buttons in emails. Signed, so they can't be guessed or reused.
Route::controller(EmailVerificationController::class)->group(function () {
    // Sign-up: the account is created when the button is pressed (App\Support\Access\PendingRegistration).
    Route::get('/email/register/{token}', 'completeRegistration')
        ->middleware(['signed', 'throttle:email-link'])->name('registration.complete');
    Route::get('/email/verify/{id}/{hash}', 'verify')->middleware('signed')->name('verification.verify');
});

// Confirms a business's or branch's contact email (App\Support\Branches\ContactEmail).
Route::get('/contact-email/verify/{kind}/{uuid}/{hash}', [ContactEmailController::class, 'verify'])
    ->middleware(['signed', 'throttle:email-link'])->name('contact-email.verify');
