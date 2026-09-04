<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AppointmentPackageRequest;
use App\Http\Requests\Business\AppointmentRequest;
use App\Http\Requests\Business\AppointmentServiceRequest;
use App\Http\Requests\Business\CancelAppointmentRequest;
use App\Http\Requests\Business\RescheduleAppointmentRequest;
use App\Service\Business\AppointmentService;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    private AppointmentService $appointmentService;

    public function __construct(AppointmentService $appointmentService)
    {
        $this->appointmentService = $appointmentService;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['date', 'status', 'client_uuid']);
        return $this->appointmentService->listAppointments($request->user(), $filters, $request->input('per_page', 15));
    }

    public function store(AppointmentRequest $request)
    {
        return $this->appointmentService->createAppointment($request->user(), $request->validated());
    }

    public function show(Request $request, string $uuid)
    {
        return $this->appointmentService->getAppointment($request->user(), $uuid);
    }

    public function checkIn(Request $request, string $uuid)
    {
        return $this->appointmentService->checkIn($request->user(), $uuid);
    }

    public function cancel(CancelAppointmentRequest $request, string $uuid)
    {
        return $this->appointmentService->cancelAppointment($request->user(), $uuid, $request->validated()['cancellation_reason'] ?? null);
    }

    public function markNoShow(Request $request, string $uuid)
    {
        return $this->appointmentService->markNoShow($request->user(), $uuid);
    }

    public function callQueue(Request $request, string $uuid)
    {
        return $this->appointmentService->callQueue($request->user(), $uuid);
    }

    public function skipQueue(Request $request, string $uuid)
    {
        return $this->appointmentService->skipQueue($request->user(), $uuid);
    }

    public function recallQueue(Request $request, string $uuid)
    {
        return $this->appointmentService->recallQueue($request->user(), $uuid);
    }

    public function reschedule(RescheduleAppointmentRequest $request, string $uuid)
    {
        return $this->appointmentService->rescheduleAppointment($request->user(), $uuid, $request->validated());
    }

    public function addService(AppointmentServiceRequest $request, string $uuid)
    {
        return $this->appointmentService->addService($request->user(), $uuid, $request->validated());
    }

    public function removeService(Request $request, string $uuid)
    {
        return $this->appointmentService->removeService($request->user(), $uuid);
    }

    public function addPackage(AppointmentPackageRequest $request, string $uuid)
    {
        return $this->appointmentService->addPackage($request->user(), $uuid, $request->validated());
    }

    public function proceedToBilling(Request $request, string $uuid)
    {
        return $this->appointmentService->proceedToBilling($request->user(), $uuid);
    }
}
