<?php

use App\Http\Controllers\Business\BusinessRegistrationChangeController;
use App\Http\Controllers\Business\OwnerIdentityChangeController;
use App\Http\Controllers\Business\OwnerVerificationController;
use Illuminate\Support\Facades\Route;

// Proving who the owner is and that the business is real, and changing either later. Each is
// checked by a system administrator (routes/api/system/businesses.php).

// Owner identity and business verification. The first steps (personal details, creating the
// business) are part of it too; there is no separate onboarding.
Route::controller(OwnerVerificationController::class)->prefix('owner/verification')->group(function () {
    Route::get('/', 'show');
    Route::post('identity/personal', 'savePersonalDetails');
    Route::post('identity', 'saveIdentity');
    Route::post('identity/face-scan', 'saveFaceScan');
    Route::post('business/details', 'saveBusinessDetails');
    Route::post('business', 'saveBusiness');
    Route::post('submit', 'submit');
});

// A verified owner's new name or email: confirmed by a code, then a new ID and face scan.
Route::controller(OwnerIdentityChangeController::class)->prefix('owner/identity-change')->group(function () {
    Route::get('/', 'show');
    Route::post('details', 'details')->middleware('throttle:identity-change');
    Route::post('email/resend', 'resendCode')->middleware('throttle:identity-change-code');
    Route::post('email/confirm', 'confirmEmail')->middleware('throttle:identity-change-confirm');
    Route::post('id', 'saveId');
    Route::post('face-scan', 'saveFaceScan');
    Route::post('submit', 'submit');
    Route::post('cancel', 'cancel');
});

// A verified business's new company registration: the new details and certificate.
Route::controller(BusinessRegistrationChangeController::class)->prefix('owner/registration-change')->group(function () {
    Route::get('/', 'show');
    Route::post('/', 'submit')->middleware('throttle:registration-change');
    Route::post('cancel', 'cancel');
});
