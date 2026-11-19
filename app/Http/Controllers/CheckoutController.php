<?php

namespace App\Http\Controllers;

use App\Http\Requests\Business\AutoRenewRequest;
use App\Http\Requests\Business\CheckoutQuoteRequest;
use App\Http\Requests\Business\GcashNumberRequest;
use App\Http\Requests\CheckoutRequest;
use App\Service\Subscription\BusinessPaymentMethodService;
use App\Service\Subscription\CheckoutService;
use App\Service\Subscription\SubscriptionRenewalService;
use Illuminate\Http\Request;

// Owner-only: Servora's subscription checkout, auto-renew and saved payment
// methods (CheckoutService, SubscriptionRenewalService,
// BusinessPaymentMethodService).
class CheckoutController extends Controller
{
    public function __construct(
        private CheckoutService $checkout,
        private SubscriptionRenewalService $renewals,
        private BusinessPaymentMethodService $methods,
    ) {}

    public function quote(CheckoutQuoteRequest $request)
    {
        $data = $request->validated();

        return response()->json(['data' => $this->checkout->quote(
            $request->user(), $data['purpose'], $data['plan_uuid'] ?? null, $data['billing_cycle'] ?? null, $data,
        )]);
    }

    public function start(CheckoutRequest $request)
    {
        return response()->json(['data' => $this->checkout->start(
            $request->user(), $request->validated(), $request->headers->get('Origin'),
        )]);
    }

    public function status(Request $request, string $uuid)
    {
        return response()->json(['data' => $this->checkout->status($request->user(), $uuid)]);
    }

    public function setAutoRenew(AutoRenewRequest $request)
    {
        return $this->renewals->setAutoRenew($request->user(), $request->validated()['enabled']);
    }

    public function addGcash(GcashNumberRequest $request)
    {
        $data = $request->validated();

        return $this->methods->addGcash($request->user(), $data['mobile_number']);
    }

    public function makeDefaultMethod(Request $request, string $uuid)
    {
        return $this->methods->makeDefault($request->user(), $uuid);
    }

    public function removeMethod(Request $request, string $uuid)
    {
        return $this->methods->remove($request->user(), $uuid);
    }
}
