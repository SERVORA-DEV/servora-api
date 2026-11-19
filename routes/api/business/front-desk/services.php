<?php

use App\Http\Controllers\Business\AppointmentServiceController;
use Illuminate\Support\Facades\Route;

// The services on a visit: who does each one and in which room, starting and finishing it,
// making it longer, and add-ons.

Route::controller(AppointmentServiceController::class)->group(function () {
    Route::prefix('appointment/{uuid}')->group(function () {
        Route::post('additional-services', 'addAdditionalService')->middleware('permission:appointment_update');

        // The Assign drawer: every therapist and room against every service still to do, and
        // saving all of it together (all or nothing).
        Route::get('assignment-options', 'assignmentOptions')->middleware('permission:appointment_view');
        Route::post('auto-assign', 'autoAssign')->middleware('permission:appointment_update');
        Route::post('assignments/batch', 'assignBatch')->middleware('permission:appointment_update');
    });

    Route::prefix('appointment-service/{uuid}')->group(function () {
        Route::get('therapist-options', 'therapistOptions')->middleware('permission:appointment_view');
        // Every room, with why it can't take this service (booked, maintenance, not set up).
        Route::get('facility-options', 'facilityOptions')->middleware('permission:appointment_view');
        Route::post('assignments', 'assignTherapist')->middleware('permission:appointment_update');

        Route::post('start', 'startService')->middleware('permission:appointment_complete');
        Route::post('complete', 'completeService')->middleware('permission:appointment_complete');

        // The client wants longer: a longer length of the same service.
        Route::get('extend-options', 'extendOptions')->middleware('permission:appointment_view');
        Route::post('extend', 'extendService')->middleware('permission:appointment_update');
        // An add-on, from the ones available for this service.
        Route::post('addons', 'addAddon')->middleware('permission:appointment_update');
    });

    Route::prefix('assignment/{uuid}')->middleware('permission:appointment_update')->group(function () {
        Route::delete('/', 'cancelAssignment');
        Route::patch('room', 'assignRoom');
        // Clears the room only; the therapist stays on.
        Route::delete('room', 'clearRoom');
    });
});
