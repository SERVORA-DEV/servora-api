<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\Business\FrontOfficeLookupService;
use Illuminate\Http\Request;

class FrontOfficeLookupController extends Controller
{
    private FrontOfficeLookupService $frontOfficeLookupService;

    public function __construct(FrontOfficeLookupService $frontOfficeLookupService)
    {
        $this->frontOfficeLookupService = $frontOfficeLookupService;
    }

    public function therapists(Request $request)
    {
        return $this->frontOfficeLookupService->therapists($request->user());
    }

    public function therapist(Request $request, string $uuid)
    {
        return $this->frontOfficeLookupService->therapist($request->user(), $uuid);
    }

    public function facilities(Request $request)
    {
        return $this->frontOfficeLookupService->facilities(
            $request->user(),
            $request->query('service_variant_uuid'),
            $request->query('appointment_date'),
            $request->query('appointment_time'),
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

    public function schedule(Request $request)
    {
        return $this->frontOfficeLookupService->schedule($request->user());
    }

    public function busyTimes(Request $request)
    {
        $request->validate(['date' => 'required|date']);
        return $this->frontOfficeLookupService->busyTimes($request->user(), $request->input('date'));
    }
}
