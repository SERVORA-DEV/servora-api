<?php

namespace App\Service;

use App\Models\BusinessPaymentMethod;
use App\Models\Subscription;
use App\Models\User;
use App\Repository\SpaBusinessRepository;

// The owner's saved cards / GCash / Maya (My Subscription → Payment method):
// pick the default, or remove one. Adding a card or Maya goes through the checkout
// (CheckoutService, purpose save_method) so Xendit collects the details; GCash is
// added by its number here, and approved each time it is used (Xendit cannot save it).
class BusinessPaymentMethodService
{
    public function __construct(private SpaBusinessRepository $businesses) {}

    /** Adds a GCash account by its mobile number. Nothing is charged and nothing is sent to Xendit. */
    public function addGcash(User $owner, string $mobile)
    {
        $business = $this->businesses->findByOwnerId($owner->id);
        abort_if(! $business, 404);

        if (! BusinessPaymentMethod::canHoldWithoutToken()) {
            return response()->json(['message' => 'Adding GCash isn\'t available yet. Please try again later.'], 422);
        }

        $digits = preg_replace('/\D/', '', $mobile);
        $last4 = substr($digits, -4);
        if (BusinessPaymentMethod::where('spa_business_id', $business->id)->where('type', 'GCASH')->where('last4', $last4)->exists()) {
            return response()->json(['message' => 'A GCash account ending in ' . $last4 . ' is already saved.'], 422);
        }

        $method = BusinessPaymentMethod::create([
            'spa_business_id' => $business->id,
            'type' => 'GCASH',
            'xendit_payment_token_id' => null,
            'label' => 'GCash · •••• ' . $last4,
            'last4' => $last4,
            'status' => 'active',
            // Never the default: the default is what renews the plan by itself.
            'is_default' => false,
        ]);

        return response()->json([
            'message' => 'GCash is added. You approve it on the GCash page each time you pay with it.',
            'data' => $method->present(),
        ], 201);
    }

    public function makeDefault(User $owner, string $uuid)
    {
        $method = $this->find($owner, $uuid);
        if ($method->paysEachTime()) {
            return response()->json(['message' => 'GCash is approved each time you pay, so it can\'t be the default for automatic renewal. Use a card or Maya for that.'], 422);
        }

        BusinessPaymentMethod::where('spa_business_id', $method->spa_business_id)->update(['is_default' => false]);
        $method->update(['is_default' => true]);

        // A running auto-renewal charges the default from now on.
        Subscription::where('spa_business_id', $method->spa_business_id)
            ->where('status', 'Active')->where('auto_renew', true)
            ->update(['payment_method_id' => $method->id]);

        return response()->json(['message' => "{$method->label} is now your default payment method."]);
    }

    public function remove(User $owner, string $uuid)
    {
        $method = $this->find($owner, $uuid);
        $businessId = $method->spa_business_id;
        $method->update(['is_default' => false]);
        $method->delete();

        // Only a method that can be charged by itself takes over a renewal.
        $next = BusinessPaymentMethod::where('spa_business_id', $businessId)->where('status', 'active')
            ->whereNotNull('xendit_payment_token_id')->latest()->first();
        $next?->update(['is_default' => true]);

        // Auto-renewal that relied on it moves to the next method, or stops.
        $renewing = Subscription::where('spa_business_id', $businessId)->where('status', 'Active')
            ->where('auto_renew', true)->where('payment_method_id', $method->id)->get();
        foreach ($renewing as $subscription) {
            $subscription->update($next
                ? ['payment_method_id' => $next->id]
                : ['auto_renew' => false, 'payment_method_id' => null]);
        }

        return response()->json([
            'message' => $renewing->isNotEmpty() && ! $next
                ? "{$method->label} was removed. Auto-renew is now off — add a payment method to turn it back on."
                : "{$method->label} was removed.",
        ]);
    }

    private function find(User $owner, string $uuid): BusinessPaymentMethod
    {
        $business = $this->businesses->findByOwnerId($owner->id);
        abort_if(! $business, 404);

        return BusinessPaymentMethod::where('spa_business_id', $business->id)->where('uuid', $uuid)->firstOrFail();
    }
}
