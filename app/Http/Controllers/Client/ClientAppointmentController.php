<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ClientAppointmentRequest;
use App\Service\Business\AppointmentBookingService;

class ClientAppointmentController extends Controller
{

    public function __construct(
        private AppointmentBookingService $booking,
    ) {}

    public function store(ClientAppointmentRequest $request)
    {
        return $this->booking->createClientAppointment($request->user(), $request->validated());
    }
}
