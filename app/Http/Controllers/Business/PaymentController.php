<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\BillingPaymentRequest;
use App\Service\Business\AppointmentService;

class PaymentController extends Controller
{
    private AppointmentService $appointmentService;

    public function __construct(AppointmentService $appointmentService)
    {
        $this->appointmentService = $appointmentService;
    }

    public function store(BillingPaymentRequest $request, string $uuid)
    {
        return $this->appointmentService->recordPayment($request->user(), $uuid, $request->validated());
    }
}
