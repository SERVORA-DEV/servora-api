<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\BillingPaymentRequest;
use App\Service\Business\AppointmentService;
use App\Service\Business\BillingService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    private AppointmentService $appointmentService;
    private BillingService $billingService;

    public function __construct(AppointmentService $appointmentService, BillingService $billingService)
    {
        $this->appointmentService = $appointmentService;
        $this->billingService = $billingService;
    }

    public function store(BillingPaymentRequest $request, string $uuid)
    {
        return $this->appointmentService->recordPayment($request->user(), $uuid, $request->validated());
    }

    // A payment entered by mistake — see BillingService::voidPayment.
    public function void(Request $request, string $uuid)
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        return $this->billingService->voidPayment($request->user(), $uuid, $reason, $request);
    }
}
