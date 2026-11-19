<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AvailabilityCheckRequest;
use App\Http\Requests\Business\BookingBoardRequest;
use App\Http\Requests\Business\BusyTimesRequest;
use App\Http\Requests\Business\SharedPackagesRequest;
use App\Service\Business\AvailabilityCheckService;
use App\Service\Business\BookingBoardService;
use App\Service\Business\FrontOfficeLookupService;
use Illuminate\Http\Request;

class FrontOfficeLookupController extends Controller
{
    public function __construct(
        private FrontOfficeLookupService $frontOfficeLookupService,
    ) {}

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
    public function availabilityCheck(AvailabilityCheckRequest $request, AvailabilityCheckService $check)
    {
        $data = $request->validated();

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

    // The packages booked for several clients on a day (and time): for adding the others.
    public function sharedPackages(SharedPackagesRequest $request)
    {
        $query = $request->validated();

        return $this->frontOfficeLookupService->sharedPackages($request->user(), $query);
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

    public function busyTimes(BusyTimesRequest $request)
    {
        return $this->frontOfficeLookupService->busyTimes($request->user(), $request->input('date'));
    }

    // The New Appointment page: one day, with who can take a visit of this
    // length at each start time.
    public function bookingBoard(BookingBoardRequest $request, BookingBoardService $bookingBoardService)
    {
        $data = $request->validated();

        return $bookingBoardService->board($request->user(), $data['date'], (int) ($data['duration'] ?? 60), isset($data['step']) ? (int) $data['step'] : null, $data['exclude_appointment_uuid'] ?? null, (bool) ($data['home_service'] ?? false));
    }
}
