<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AddAddonRequest;
use App\Http\Requests\Business\AppointmentServiceRequest;
use App\Http\Requests\Business\AutoAssignRequest;
use App\Http\Requests\Business\BatchAssignmentRequest;
use App\Http\Requests\Business\ExtendServiceRequest;
use App\Service\Business\AssignmentPlanner;
use App\Http\Requests\Business\FacilityAssignmentRequest;
use App\Http\Requests\Business\TherapistAssignmentRequest;
use App\Service\Business\ServiceExecutionService;
use App\Service\Business\ServiceExtensionService;
use App\Service\Business\VisitAssignmentService;
use App\Service\Business\VisitItemsService;
use Illuminate\Http\Request;

// Everything scoped to one specific booked service (or one specific
// therapist/room assignment on it) — as opposed to AppointmentController,
// which acts on the appointment header.
class AppointmentServiceController extends Controller
{
    public function __construct(
        private ServiceExecutionService $execution,
        private VisitItemsService $items,
        private VisitAssignmentService $assignments,
    ) {}

    public function therapistOptions(Request $request, string $uuid)
    {
        return $this->assignments->therapistOptions($request->user(), $uuid);
    }

    // Every therapist and facility against every service still to be done on one booking (the Assign drawer).
    public function assignmentOptions(Request $request, string $uuid, AssignmentPlanner $planner)
    {
        return $planner->options($request->user(), $uuid);
    }

    // Gives the booking the staff and facility it lacks (the Assign drawer's Auto-assign); with
    // mode=replace_unavailable also replaces staff on leave, absent or on a day off.
    public function autoAssign(AutoAssignRequest $request, string $uuid)
    {
        $mode = $request->validated()['mode'] ?? 'missing';

        return $this->assignments->autoAssignAppointment($request->user(), $uuid, $mode);
    }

    // Several of the booking's services given staff and facility together, all or nothing.
    public function assignBatch(BatchAssignmentRequest $request, string $uuid)
    {
        return $this->assignments->assignBatch($request->user(), $uuid, $request->validated()['assignments']);
    }

    public function facilityOptions(Request $request, string $uuid)
    {
        return $this->assignments->facilityOptions($request->user(), $uuid);
    }

    public function assignTherapist(TherapistAssignmentRequest $request, string $uuid)
    {
        return $this->assignments->assignTherapist($request->user(), $uuid, $request->validated());
    }

    public function cancelAssignment(Request $request, string $uuid)
    {
        return $this->assignments->cancelAssignment($request->user(), $uuid);
    }

    public function assignRoom(FacilityAssignmentRequest $request, string $uuid)
    {
        return $this->assignments->assignRoom($request->user(), $uuid, $request->validated());
    }

    public function clearRoom(Request $request, string $uuid)
    {
        return $this->assignments->clearRoom($request->user(), $uuid);
    }

    public function startService(Request $request, string $uuid)
    {
        return $this->execution->startService($request->user(), $uuid);
    }

    public function completeService(Request $request, string $uuid)
    {
        return $this->execution->completeService($request->user(), $uuid);
    }

    // A service in progress, moved to a longer length of the same service.
    public function extendOptions(Request $request, string $uuid, ServiceExtensionService $extension)
    {
        return $extension->options($request->user(), $uuid);
    }

    public function extendService(ExtendServiceRequest $request, string $uuid, ServiceExtensionService $extension)
    {
        $payload = $request->validated();

        return $extension->extend($request->user(), $uuid, $payload);
    }

    // An add-on put on this service (hot stones on the massage) — one of
    // the owner's add-ons that is available for it. See
    // VisitItemsService::addAddon.
    public function addAddon(AddAddonRequest $request, string $uuid)
    {
        $payload = $request->validated();

        return $this->items->addAddon($request->user(), $uuid, $payload);
    }

    // uuid here is the appointment's uuid (route: appointment/{uuid}/additional-services)
    // — kept on this controller alongside the other per-service actions
    // since it shares AppointmentServiceRequest's validation shape, even
    // though it targets the appointment rather than one existing service.
    public function addAdditionalService(AppointmentServiceRequest $request, string $uuid)
    {
        return $this->items->addService($request->user(), $uuid, $request->validated());
    }
}
