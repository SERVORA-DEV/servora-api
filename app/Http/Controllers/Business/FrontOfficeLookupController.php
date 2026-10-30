<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\Business\AvailabilityCheckService;
use App\Service\Business\BookingBoardService;
use App\Service\Business\FrontOfficeLookupService;
use Illuminate\Http\Request;

class FrontOfficeLookupController extends Controller
{
    private FrontOfficeLookupService $frontOfficeLookupService;

    public function __construct(FrontOfficeLookupService $frontOfficeLookupService)
    {
        $this->frontOfficeLookupService = $frontOfficeLookupService;
    }

    public function branches(Request $request)
    {
        return response()->json(['data' => $this->frontOfficeLookupService->branches($request->user())]);
    }

    public function therapists(Request $request)
    {
        return $this->frontOfficeLookupService->therapists($request->user());
    }

    public function therapist(Request $request, string $uuid)
    {
        return $this->frontOfficeLookupService->therapist($request->user(), $uuid);
    }

    public function therapistQueue(Request $request)
    {
        return $this->frontOfficeLookupService->therapistQueue($request->user(), $request->query('date'));
    }

    // Who and what can do one service at a date and time (Appointments → Availability).
    public function availabilityCheck(Request $request, AvailabilityCheckService $check)
    {
        $data = $request->validate([
            'service_variant_uuid' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ]);

        return $check->check($request->user(), $data['service_variant_uuid'], $data['date'], $data['time']);
    }

    public function facilities(Request $request)
    {
        return $this->frontOfficeLookupService->facilities(
            $request->user(),
            $request->query('service_variant_uuid'),
            $request->query('appointment_date'),
            $request->query('appointment_time'),
            $request->query('exclude_appointment_uuid'),
        );
    }

    public function services(Request $request)
    {
        return $this->frontOfficeLookupService->services($request->user());
    }

    public function packages(Request $request)
    {
        return $this->frontOfficeLookupService->packages($request->user());
    }

    public function addons(Request $request)
    {
        return $this->frontOfficeLookupService->addons($request->user());
    }

    public function schedule(Request $request)
    {
        return $this->frontOfficeLookupService->schedule($request->user());
    }

    public function scannerLink(Request $request)
    {
        return $this->frontOfficeLookupService->scannerLink($request->user());
    }

    public function replaceScannerLink(Request $request)
    {
        return $this->frontOfficeLookupService->scannerLink($request->user(), true);
    }

    public function busyTimes(Request $request)
    {
        $request->validate(['date' => 'required|date']);
        return $this->frontOfficeLookupService->busyTimes($request->user(), $request->input('date'));
    }

    // The New Appointment page: one day, with who can take a visit of this
    // length at each start time.
    public function bookingBoard(Request $request, BookingBoardService $bookingBoardService)
    {
        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'duration' => 'nullable|integer|min:15|max:720',
            // The spacing of start times: the New Appointment page asks for 5.
            'step' => 'nullable|integer|in:5,10,15,30,60',
            // Rescheduling: the booking being moved doesn't count against itself.
            'exclude_appointment_uuid' => 'nullable|uuid',
            // A home service visit: only the staff available for home service can take it.
            'home_service' => 'nullable|boolean',
        ]);

        return $bookingBoardService->board($request->user(), $data['date'], (int) ($data['duration'] ?? 60), isset($data['step']) ? (int) $data['step'] : null, $data['exclude_appointment_uuid'] ?? null, (bool) ($data['home_service'] ?? false));
    }
}
