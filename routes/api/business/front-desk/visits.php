<?php

use App\Http\Controllers\Business\AppointmentController;
use App\Http\Controllers\Business\ClientController;
use App\Http\Controllers\Business\QueueController;
use Illuminate\Support\Facades\Route;

// Clients and their visits, from booking to the bill.

// The spa's clients.
Route::controller(ClientController::class)->prefix('client')->group(function () {
    Route::get('/', 'index')->middleware('permission:client_view');
    Route::post('/', 'store')->middleware('permission:client_create');
    // The Clients page's figures. Before {uuid}, which would take "summary" for a uuid.
    Route::get('summary', 'summary')->middleware('permission:client_view');
    Route::get('{uuid}', 'show')->middleware('permission:client_view');
    Route::patch('{uuid}', 'update')->middleware('permission:client_update');
    // Takes the client's picture down at this spa (their account keeps it).
    Route::delete('{uuid}/photo', 'dismissPhoto')->middleware('permission:client_update');
});

Route::controller(AppointmentController::class)->group(function () {
    // Visits left open from days that are over: how many, and close them now.
    Route::get('past-visits', 'pastVisits')->middleware('permission:appointment_view');
    Route::post('past-visits/close', 'closePastVisits')->middleware('permission:appointment_update');

    Route::get('appointment', 'index')->middleware('permission:appointment_view');
    Route::post('appointment', 'store')->middleware('permission:appointment_create');
    // The figures above the owner's list, and a branch's visits per day for its calendar.
    // Before appointment/{uuid}, which would take either word for a uuid.
    Route::get('appointment/summary', 'summary')->middleware('permission:appointment_view');
    Route::get('appointment/day-counts', 'dayCounts')->middleware('permission:appointment_view');

    Route::prefix('appointment/{uuid}')->group(function () {
        Route::get('/', 'show')->middleware('permission:appointment_view');
        Route::post('checkin', 'checkIn')->middleware('permission:appointment_checkin');
        // A home visit started at the desk without the client's QR: checked in and started, no queue.
        Route::post('home-start', 'startHomeVisit')->middleware('permission:appointment_complete');
        Route::post('cancel', 'cancel')->middleware('permission:appointment_cancel');
        Route::post('no-show', 'markNoShow')->middleware('permission:appointment_cancel');
        Route::patch('reschedule', 'reschedule')->middleware('permission:appointment_update');
        Route::post('services', 'addService')->middleware('permission:appointment_update');
        Route::post('packages', 'addPackage')->middleware(['permission:appointment_update', 'plan.feature:package_access,open']);
        Route::post('billing', 'proceedToBilling')->middleware('permission:billing_create');

        // The waiting queue at the desk.
        Route::middleware('permission:queue_update')->group(function () {
            Route::post('queue/call', 'callQueue');
            Route::post('queue/skip', 'skipQueue');
            Route::post('queue/recall', 'recallQueue');
        });
    });

    Route::delete('appointment-service/{uuid}', 'removeService')->middleware('permission:appointment_update');
    // Taking a started or finished service off the visit changes what is charged: for staff
    // allowed to change a bill.
    Route::post('appointment-service/{uuid}/void', 'voidService')->middleware('permission:billing_update');
});

Route::get('queue', [QueueController::class, 'index'])->middleware('permission:queue_view');
