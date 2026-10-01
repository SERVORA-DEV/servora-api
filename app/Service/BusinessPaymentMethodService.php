<?php

namespace App\Service;

use App\Models\BusinessPaymentMethod;
use App\Models\Subscription;
use App\Models\User;
use App\Repository\SpaBusinessRepository;

// The owner's saved cards / GCash / Maya (My Subscription → Payment method):
// pick the default, or remove one. Adding one goes through the checkout
// (CheckoutService, purpose save_method) so Xendit collects the details.
class BusinessPaymentMethodService
{
    public function __construct(private SpaBusinessRepository $businesses) {}

    public function makeDefault(User $owner, string $uuid)
    {
        $method = $this->find($owner, $uuid);

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

        $next = BusinessPaymentMethod::where('spa_business_id', $businessId)->where('status', 'active')->latest()->first();
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
