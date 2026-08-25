<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AppointmentServiceRequest;
use App\Http\Requests\Business\FacilityAssignmentRequest;
use App\Http\Requests\Business\TherapistAssignmentRequest;
use App\Service\Business\AppointmentService;
use Illuminate\Http\Request;

// Everything scoped to one specific booked service (or one specific
// therapist/room assignment on it) — as opposed to AppointmentController,
// which acts on the appointment header.
class AppointmentServiceController extends Controller
{
    private AppointmentService $appointmentService;

    public function __construct(AppointmentService $appointmentService)
    {
        $this->appointmentService = $appointmentService;
    }

    public function assignTherapist(TherapistAssignmentRequest $request, string $uuid)
    {
        return $this->appointmentService->assignTherapist($request->user(), $uuid, $request->validated());
    }

    public function cancelAssignment(Request $request, string $uuid)
    {
        return $this->appointmentService->cancelAssignment($request->user(), $uuid);
    }

    public function assignRoom(FacilityAssignmentRequest $request, string $uuid)
    {
        return $this->appointmentService->assignRoom($request->user(), $uuid, $request->validated());
    }

    public function startService(Request $request, string $uuid)
    {
        return $this->appointmentService->startService($request->user(), $uuid);
    }

    public function completeService(Request $request, string $uuid)
    {
        return $this->appointmentService->completeService($request->user(), $uuid);
    }

    // uuid here is the appointment's uuid (route: appointment/{uuid}/additional-services)
    // — kept on this controller alongside the other per-service actions
    // since it shares AppointmentServiceRequest's validation shape, even
    // though it targets the appointment rather than one existing service.
    public function addAdditionalService(AppointmentServiceRequest $request, string $uuid)
    {
        return $this->appointmentService->addService($request->user(), $uuid, $request->validated());
    }
}
