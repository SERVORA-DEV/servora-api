<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ClientAppointmentRequest;
use App\Service\Business\AppointmentService;

class ClientAppointmentController extends Controller
{
    private AppointmentService $appointmentService;

    public function __construct(AppointmentService $appointmentService)
    {
        $this->appointmentService = $appointmentService;
    }

    public function store(ClientAppointmentRequest $request)
    {
        return $this->appointmentService->createClientAppointment($request->user(), $request->validated());
    }
}
