<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Service\BusinessPaymentMethodService;
use App\Service\CheckoutService;
use App\Service\SubscriptionRenewalService;
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

    public function quote(Request $request)
    {
        $data = $request->validate([
            'purpose' => 'required|in:subscribe,renew,upgrade,save_method',
            'plan_uuid' => 'nullable|uuid',
            'billing_cycle' => 'nullable|in:Monthly,Yearly',
        ]);

        return response()->json(['data' => $this->checkout->quote(
            $request->user(), $data['purpose'], $data['plan_uuid'] ?? null, $data['billing_cycle'] ?? null,
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

    public function setAutoRenew(Request $request)
    {
        return $this->renewals->setAutoRenew($request->user(), $request->validate(['enabled' => 'required|boolean'])['enabled']);
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
