<?php

use App\Http\Controllers\Business\SpaBranchController;
use App\Http\Controllers\Business\SpaBusinessController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MobileReleaseController;
use App\Http\Controllers\ScannerController;
use App\Http\Controllers\ServoraReviewController;
use App\Http\Controllers\System\SubscriptionPlanController;
use App\Http\Controllers\XenditWebhookController;
use Illuminate\Support\Facades\Route;

// Open to anyone: what the website and the client app show before signing in.

// Terms of Service and Privacy Policy.
Route::get('/legal', [LegalController::class, 'show']);

// The plans on the pricing page.
Route::get('/active-subscription-plans', [SubscriptionPlanController::class, 'displayActivePlans']);

// A business's branded sign-in page (PublicSpaBusinessResource says what is shown).
Route::get('/business/{uuid}/public', [SpaBusinessController::class, 'publicShow']);

// Owners' reviews of SERVORA that an administrator published (the website's Reviews page).
Route::get('/servora-reviews', [ServoraReviewController::class, 'published'])->middleware('throttle:public-read');

// The newest mobile app version and the oldest still accepted, so an installed app can offer
// (or require) the update. Set in .env (MOBILE_LATEST_VERSION, MOBILE_LATEST_BUILD, MOBILE_APK_URL).
Route::get('/app/release', [MobileReleaseController::class, 'show'])->middleware('throttle:public-read');

// The client app's spa pages and booking flow.
Route::controller(SpaBranchController::class)->prefix('spas')->group(function () {
    // Before spas/{uuid}, which would take "nearby" for a uuid.
    Route::get('nearby', 'nearby');
    Route::get('{uuid}', 'publicShow');

    // Which therapists can take a booking at a given date, time and length.
    Route::get('{uuid}/therapists', 'publicTherapistAvailability');
    // When each therapist is next free, and that day's free stretches (the staff step).
    Route::get('{uuid}/therapists/free-times', 'publicTherapistFreeTimes');
    // The start times a client can book on one day (BookingSlotService).
    Route::get('{uuid}/slots', 'publicSlots');
    // The dates a therapist is off, greyed out on the calendar. Dates only, never shift times.
    Route::get('{uuid}/therapists/{staffUuid}/days-off', 'publicTherapistDaysOff');

    // Published reviews: first name and last initial only.
    Route::get('{uuid}/reviews', 'publicReviews');
});

// A branch's scanner. Staff open the link the front desk gave them and scan a client's QR to
// start a home visit; the secret in the link names the branch. Throttled against guessing.
Route::controller(ScannerController::class)->prefix('scanner/{token}')->group(function () {
    Route::get('/', 'show')->middleware('throttle:scanner');
    Route::post('start', 'start')->middleware('throttle:scanner-start');

    // The visit at the client's door, step by step. The page re-reads the visit while it
    // runs, so these get a roomier limit of their own.
    Route::prefix('visit')->middleware('throttle:scanner-visit')->group(function () {
        Route::post('look', 'look');
        Route::post('start', 'startVisit');
        Route::post('extend-options', 'extendOptions');
        Route::post('extend', 'extend');
        Route::post('complete', 'complete');
        Route::post('checkout', 'checkout');
    });
});

// Xendit's server-to-server callback: no sign-in, checked by its x-callback-token.
Route::post('/webhooks/xendit', [XenditWebhookController::class, 'handle']);
