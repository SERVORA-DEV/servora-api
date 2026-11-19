<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\BillingPaymentRequest;
use App\Http\Requests\Business\VoidPaymentRequest;
use App\Service\Business\BillingService;
use App\Service\Business\VisitBillingService;

class PaymentController extends Controller
{
    public function __construct(
        private VisitBillingService $visitBilling,
        private BillingService $billingService,
    ) {}

    public function store(BillingPaymentRequest $request, string $uuid)
    {
        return $this->visitBilling->recordPayment($request->user(), $uuid, $request->validated());
    }

    // A payment entered by mistake — see BillingService::voidPayment.
    public function void(VoidPaymentRequest $request, string $uuid)
    {
        $reason = $request->validated()['reason'];
        return $this->billingService->voidPayment($request->user(), $uuid, $reason, $request);
    }
}
