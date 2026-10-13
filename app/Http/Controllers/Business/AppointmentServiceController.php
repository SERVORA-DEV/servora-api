<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AppointmentServiceRequest;
use App\Http\Requests\Business\FacilityAssignmentRequest;
use App\Http\Requests\Business\TherapistAssignmentRequest;
use App\Service\Business\AppointmentService;
use App\Service\Business\ServiceExtensionService;
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

    public function therapistOptions(Request $request, string $uuid)
    {
        return $this->appointmentService->therapistOptions($request->user(), $uuid);
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

    public function clearRoom(Request $request, string $uuid)
    {
        return $this->appointmentService->clearRoom($request->user(), $uuid);
    }

    public function startService(Request $request, string $uuid)
    {
        return $this->appointmentService->startService($request->user(), $uuid);
    }

    public function completeService(Request $request, string $uuid)
    {
        return $this->appointmentService->completeService($request->user(), $uuid);
    }

    // A service in progress, moved to a longer length of the same service.
    public function extendOptions(Request $request, string $uuid, ServiceExtensionService $extension)
    {
        return $extension->options($request->user(), $uuid);
    }

    public function extendService(Request $request, string $uuid, ServiceExtensionService $extension)
    {
        $payload = $request->validate(['service_variant_uuid' => 'required|uuid']);

        return $extension->extend($request->user(), $uuid, $payload);
    }

    // An add-on put on this service (hot stones on the massage) — one of
    // the owner's add-ons that is available for it. See
    // AppointmentService::addAddon.
    public function addAddon(Request $request, string $uuid)
    {
        $payload = $request->validate(['addon_uuid' => 'required|uuid']);

        return $this->appointmentService->addAddon($request->user(), $uuid, $payload);
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
