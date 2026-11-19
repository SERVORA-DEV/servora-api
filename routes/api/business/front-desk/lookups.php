<?php

use App\Http\Controllers\Business\FrontOfficeAttendanceController;
use App\Http\Controllers\Business\FrontOfficeDashboardController;
use App\Http\Controllers\Business\FrontOfficeLookupController;
use App\Http\Controllers\Business\StaffScheduleController;
use App\Http\Controllers\Business\StaffTimeOffController;
use Illuminate\Support\Facades\Route;

// The front desk's own screens of its branch: the dashboard, today's attendance, and the
// lookups the booking and assigning screens read. Reads only, unless said.

Route::prefix('frontoffice')->group(function () {
    Route::get('dashboard', [FrontOfficeDashboardController::class, 'index'])->middleware('permission:dashboard_view');

    // Attendance at the desk: today only, checking in and out (FrontOfficeAttendanceService says
    // why this is apart from the owner's and managers' attendance).
    Route::controller(FrontOfficeAttendanceController::class)->prefix('attendance')->group(function () {
        Route::get('/', 'index')->middleware('permission:attendance_view');
        Route::middleware('permission:attendance_checkin')->group(function () {
            Route::post('check-in', 'checkIn');
            Route::post('check-out', 'checkOut');
            // Sending a therapist home early, or marking them on leave today.
            Route::post('leave', 'leave');
        });
        // Who could fill in for them (lowest earnings first).
        Route::get('fill-in-candidates', 'fillInCandidates')->middleware('permission:attendance_view');
        // After every literal attendance/… path above.
        Route::get('{uuid}/history', 'history')->middleware('permission:attendance_view');
    });

    Route::controller(FrontOfficeLookupController::class)->group(function () {
        Route::get('branches', 'branches');

        Route::middleware('permission:appointment_view')->group(function () {
            Route::get('therapists', 'therapists');
            Route::get('therapists/{uuid}', 'therapist');
            // Today's therapist rotation: who is next in line.
            Route::get('therapist-queue', 'therapistQueue');
            Route::get('availability-check', 'availabilityCheck');
            Route::get('facilities', 'facilities');
            Route::get('services', 'services');
            Route::get('packages', 'packages');
            // Packages booked for several clients that day, for adding the others (SharedPackages).
            Route::get('shared-packages', 'sharedPackages');
            Route::get('addons', 'addons');
            Route::get('schedule', 'schedule');
            Route::get('booked-times', 'busyTimes');
            // The New Appointment page: each therapist's free start times for a day and length.
            Route::get('booking-board', 'bookingBoard');
        });

        // The branch's scanner link: staff start a home visit by scanning the client's QR
        // (ScannerController).
        Route::get('scanner-link', 'scannerLink')->middleware('permission:attendance_view');
        Route::post('scanner-link/replace', 'replaceScannerLink')->middleware('permission:attendance_checkin');
    });

    // The branch's time off in a date range (the staff calendar, the dashboard).
    Route::get('time-off', [StaffTimeOffController::class, 'range'])->middleware('permission:appointment_view');
    // The branch's regular week, for the Shifts view.
    Route::get('shifts', [StaffScheduleController::class, 'branchWeek'])->middleware('permission:attendance_view');
});
