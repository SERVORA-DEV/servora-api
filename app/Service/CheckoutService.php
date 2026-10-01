<?php

namespace App\Service;

use App\Http\Resources\SubscriptionPlanResource;
use App\Models\Billing;
use App\Models\BusinessBillingAddress;
use App\Models\BusinessPaymentMethod;
use App\Models\CheckoutSession;
use App\Models\Payment;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Repository\BillingRepository;
use App\Repository\SpaBusinessRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\System\AdminUsersRepository;
use App\Repository\System\SubscriptionPlanRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Servora's own subscription checkout — the one way an owner pays:
//
//  - subscribe / renew: buy a plan when none is running (full price);
//  - upgrade: pay the difference for a bigger plan (PlanSwitchService rules);
//  - save_method: add or replace the saved payment method, nothing charged;
//  - auto_renew: the renewal charge SubscriptionRenewalService makes on a
//    saved method (no page involved).
//
// Card, GCash and Maya are collected by Xendit Components in our page (a
// Payment Session in COMPONENTS mode), so card numbers never reach Servora;
// a saved method is a Xendit payment token. Every attempt is a
// CheckoutSession row; complete() is the single, idempotent place a paid
// attempt becomes a subscription, invoice (with its 12% VAT) and saved
// method — called from the webhook, the status poll or the renewal job.
class CheckoutService
{
    // Plan prices include VAT; invoices split it out.
    public const VAT_RATE = 0.12;

    public const CHANNELS = ['CARDS', 'GCASH', 'PAYMAYA'];

    public function __construct(
        private XenditService $xendit,
        private SpaBusinessRepository $businesses,
        private SubscriptionRepository $subscriptions,
        private SubscriptionPlanRepository $plans,
        private BillingRepository $billings,
        private PlanSwitchService $planSwitch,
        private NotificationService $notifications,
        private AdminUsersRepository $admins,
    ) {}

    public static function vatOf(float $amount): float
    {
        return round($amount - $amount / (1 + self::VAT_RATE), 2);
    }

    // ── Quote ─────────────────────────────────────────────────────────────

    /**
     * What the owner is about to pay and whether they can — the checkout
     * page's right-hand summary, plus their saved methods and address.
     */
    public function quote(User $owner, string $purpose, ?string $planUuid, ?string $cycle): array
    {
        $business = $this->businessOf($owner);
        [$plan, $cycle, $amount, $blocked, $lines] = $this->price($business, $purpose, $planUuid, $cycle);
        $live = $this->subscriptions->findActiveForBusiness($business->id);

        return [
            'purpose' => $purpose,
            'allowed' => $blocked === null,
            'blocked_reason' => $blocked,
            'plan' => $plan ? new SubscriptionPlanResource($plan) : null,
            'billing_cycle' => $cycle,
            'amount' => $amount,
            'vat_amount' => self::vatOf($amount),
            'subtotal' => round($amount - self::vatOf($amount), 2),
            'lines' => $lines,
            'current_plan' => $live?->plan?->name,
            'renews_at' => $live?->expires_at,
            'auto_renew' => (bool) $live?->auto_renew,
            'payment_methods' => $this->methodsFor($business),
            'billing_address' => $this->addressFor($business),
        ];
    }

    // [plan, cycle, amount due, blocked reason or null, summary lines].
    private function price(SpaBusiness $business, string $purpose, ?string $planUuid, ?string $cycle): array
    {
        if ($purpose === 'save_method') {
            return [null, null, 0.0, null, [['label' => 'Nothing is charged now', 'amount' => 0.0]]];
        }

        $plan = $planUuid ? SubscriptionPlan::where('uuid', $planUuid)->first() : null;
        if (! $plan) {
            return [null, $cycle, 0.0, 'Choose a plan first.', []];
        }

        $live = $this->subscriptions->findActiveForBusiness($business->id);

        if ($purpose === 'upgrade') {
            if (! $live) {
                return [$plan, $cycle, 0.0, 'You have no active plan to upgrade. Choose a plan to subscribe.', []];
            }
            $quote = $this->planSwitch->quote($live, $plan, $live->billing_cycle ?? 'Monthly');
            $blocked = $quote['allowed'] ? ($quote['type'] !== 'upgrade' ? 'That plan isn\'t an upgrade — switch to it from the Plans page instead.' : null) : $quote['blocked_reason'];
            $per = ($live->billing_cycle ?? 'Monthly') === 'Yearly' ? 'year' : 'month';

            return [$plan, $live->billing_cycle ?? 'Monthly', (float) $quote['amount_due'], $blocked, [
                ['label' => "{$plan->name} plan (per {$per})", 'amount' => (float) ($quote['new_price'] ?? 0)],
                ['label' => 'Already paid this term', 'amount' => -(float) $quote['paid_this_term']],
            ]];
        }

        // subscribe / renew
        $cycle = $cycle === 'Yearly' ? 'Yearly' : 'Monthly';
        $price = PlanSwitchService::price($plan, $cycle);
        $blocked = match (true) {
            $live !== null => "You already have an active plan until {$live->expires_at?->format('F j, Y')}. Upgrade or downgrade it from the Plans page.",
            ! $plan->is_active => 'That plan is no longer offered. Please choose another.',
            $price === null => "The {$plan->name} plan isn't offered with {$cycle} billing.",
            default => null,
        };
        if (! $blocked && ($over = $this->planSwitch->overLimits($business, $plan))) {
            $blocked = implode(' ', $over) . ' Remove branches or accounts first, or pick a bigger plan.';
        }

        $amount = (float) ($price ?? 0);

        return [$plan, $cycle, $amount, $blocked, [
            ['label' => $cycle === 'Yearly' ? 'Yearly subscription' : 'Monthly subscription', 'amount' => round($amount - self::vatOf($amount), 2)],
            ['label' => 'VAT (12%)', 'amount' => self::vatOf($amount)],
        ]];
    }

    // ── Start ─────────────────────────────────────────────────────────────

    /**
     * Opens a Xendit Payment Session (COMPONENTS mode) for the checkout
     * page, or — paying with a saved method — charges its token directly.
     * Returns the session the page polls, and the Components key.
     */
    public function start(User $owner, array $payload, ?string $requestOrigin): array
    {
        $business = $this->businessOf($owner);
        $purpose = $payload['purpose'];
        [$plan, $cycle, $amount, $blocked] = $this->price($business, $purpose, $payload['plan_uuid'] ?? null, $payload['billing_cycle'] ?? null);
        if ($blocked) {
            abort(422, $blocked);
        }
        if ($purpose !== 'save_method' && $amount <= 0) {
            abort(422, 'There is nothing to pay.');
        }

        $address = $this->saveAddress($business, $payload['billing_address']);
        $live = $this->subscriptions->findActiveForBusiness($business->id);
        $wantsAutoRenew = (bool) ($payload['enable_auto_renew'] ?? false);

        $session = CheckoutSession::create([
            'spa_business_id' => $business->id,
            'purpose' => $purpose,
            'subscription_id' => $purpose === 'upgrade' ? $live?->id : ($purpose === 'save_method' ? $live?->id : null),
            'subscription_plan_id' => $plan?->id,
            'billing_cycle' => $cycle,
            'amount' => $amount,
            'vat_amount' => self::vatOf($amount),
            // Auto-renew needs a saved method; adding a method always saves it.
            'save_method' => $purpose === 'save_method' || $wantsAutoRenew || (bool) ($payload['save_method'] ?? false),
            'enable_auto_renew' => $wantsAutoRenew,
            'reference_id' => 'SRV-' . Str::uuid(),
        ]);

        if (($payload['method'] ?? null) === 'saved') {
            return $this->payWithSaved($session, $business, $payload['payment_method_uuid'] ?? null, $plan);
        }

        $origin = $this->origin($requestOrigin);
        $description = match ($purpose) {
            'save_method' => 'Save a payment method for Servora renewals',
            'upgrade' => "Upgrade to {$plan->name} Plan ({$cycle})",
            default => "{$plan->name} Plan Subscription ({$cycle})",
        };

        try {
            $xs = $this->xendit->createSession(array_filter([
                'reference_id' => $session->reference_id,
                'session_type' => $purpose === 'save_method' ? 'SAVE' : 'PAY',
                'mode' => 'COMPONENTS',
                'amount' => $purpose === 'save_method' ? 0 : $amount,
                'currency' => 'PHP',
                'country' => 'PH',
                'allow_save_payment_method' => $purpose === 'save_method' ? null : ($session->save_method ? 'FORCED' : 'DISABLED'),
                'allowed_payment_channels' => self::CHANNELS,
                'capture_method' => $purpose === 'save_method' ? null : 'AUTOMATIC',
                'description' => $description,
                'metadata' => ['servora_checkout' => $session->uuid, 'purpose' => $purpose],
                'components_configuration' => array_filter([
                    'origins' => [$origin],
                    // Where GCash / Maya / 3DS send the owner back. Xendit
                    // rejects localhost here (it accepts it as an origin), so
                    // local dev relies on the page's own polling instead.
                    'return_url' => self::isPublicOrigin($origin) ? "{$origin}/business/checkout/return?session={$session->uuid}" : null,
                ]),
            ] + $this->customerFields($business, $address), fn ($v) => $v !== null));
        } catch (XenditRequestException $e) {
            Log::warning('checkout.session_failed', ['session' => $session->uuid, 'error' => $e->getMessage()]);
            $session->update(['status' => 'failed', 'failure_reason' => 'Could not open the payment form.']);
            abort(502, 'The payment form could not be opened right now. Please try again.');
        }

        if (! $business->xendit_customer_id && ! empty($xs['customer_id'])) {
            $business->update(['xendit_customer_id' => $xs['customer_id']]);
        }
        $session->update(['xendit_session_id' => $xs['payment_session_id'] ?? null]);

        return [
            'session_uuid' => $session->uuid,
            'status' => 'pending',
            'components_sdk_key' => $xs['components_sdk_key'] ?? null,
            'amount' => $amount,
        ];
    }

    // A saved card/GCash/Maya: charge its token now — nothing to fill in.
    private function payWithSaved(CheckoutSession $session, SpaBusiness $business, ?string $methodUuid, ?SubscriptionPlan $plan): array
    {
        if ($session->purpose === 'save_method') {
            abort(422, 'Choose a new card, GCash or Maya account to save.');
        }

        $method = BusinessPaymentMethod::where('spa_business_id', $business->id)->where('uuid', $methodUuid)->where('status', 'active')->first();
        if (! $method || $method->isExpired()) {
            $session->update(['status' => 'failed', 'failure_reason' => 'That saved payment method is no longer available.']);
            abort(422, 'That saved payment method is no longer available. Add a new one.');
        }
        $session->update(['payment_method_id' => $method->id, 'save_method' => false]);

        $this->chargeSession($session, $method, "{$plan?->name} Plan ({$session->billing_cycle})");

        return ['session_uuid' => $session->uuid, 'status' => $session->fresh()->status, 'components_sdk_key' => null, 'amount' => (float) $session->amount];
    }

    // Charges a saved method for a session (checkout "Saved" tab or the
    // renewal job) and settles it when Xendit answers straight away.
    public function chargeSession(CheckoutSession $session, BusinessPaymentMethod $method, string $description): void
    {
        try {
            $pr = $this->xendit->chargeToken($session->reference_id, $method->xendit_payment_token_id, (float) $session->amount, $description, $method->type === 'CARD');
        } catch (XenditRequestException $e) {
            Log::warning('checkout.charge_failed', ['session' => $session->uuid, 'error' => $e->getMessage()]);
            $this->markFailed($session, $this->failureMessage($e->errorCode));

            return;
        }

        $session->update(['xendit_payment_request_id' => $pr['payment_request_id'] ?? null]);
        $this->settleFromPaymentRequest($session, $pr);
    }

    // ── Status / completion ───────────────────────────────────────────────

    // GET business/checkout/{uuid}: asks Xendit while still pending (the
    // webhook can't reach a local dev machine), then reports.
    public function status(User $owner, string $uuid): array
    {
        $business = $this->businessOf($owner);
        $session = CheckoutSession::where('spa_business_id', $business->id)->where('uuid', $uuid)->firstOrFail();
        $this->refresh($session);

        return $this->present($session->fresh(['plan', 'paymentMethod']));
    }

    public function refresh(CheckoutSession $session): void
    {
        if ($session->isFinal()) {
            return;
        }

        try {
            if ($session->xendit_session_id) {
                $xs = $this->xendit->getSession($session->xendit_session_id);
                match ($xs['status'] ?? null) {
                    'COMPLETED' => $this->complete($session, $xs),
                    'EXPIRED', 'CANCELED' => $session->update(['status' => 'expired', 'failure_reason' => 'The payment wasn\'t finished in time.']),
                    default => null,
                };
            } elseif ($session->xendit_payment_request_id) {
                $this->settleFromPaymentRequest($session, $this->xendit->getPaymentRequest($session->xendit_payment_request_id));
            }
        } catch (XenditRequestException $e) {
            Log::warning('checkout.refresh_failed', ['session' => $session->uuid, 'error' => $e->getMessage()]);
        }
    }

    private function settleFromPaymentRequest(CheckoutSession $session, array $pr): void
    {
        match ($pr['status'] ?? null) {
            'SUCCEEDED' => $this->complete($session, [
                'payment_request_id' => $pr['payment_request_id'] ?? null,
                'payment_token_id' => null,
                'channel_code' => $pr['channel_code'] ?? null,
            ]),
            'FAILED', 'CANCELED', 'EXPIRED' => $this->markFailed($session, $this->failureMessage($pr['failure_code'] ?? null)),
            default => null, // still processing — the webhook or next poll settles it
        };
    }

    public function markFailed(CheckoutSession $session, string $reason): void
    {
        if ($session->isFinal()) {
            return;
        }
        $session->update(['status' => 'failed', 'failure_reason' => $reason]);

        if ($session->purpose === 'auto_renew') {
            app(SubscriptionRenewalService::class)->recordFailure($session, $reason);
        }
    }

    /**
     * A paid (or saved) attempt becomes real: the subscription / upgrade /
     * saved method, the invoice with its VAT, and the payment. Safe to call
     * more than once — only the first call does anything.
     *
     * @param array $x Xendit session or payment data: payment_request_id,
     *                 payment_token_id, channel_code (any may be missing).
     */
    public function complete(CheckoutSession $session, array $x): void
    {
        $done = DB::transaction(function () use ($session, $x) {
            $session = CheckoutSession::whereKey($session->id)->lockForUpdate()->first();
            if ($session->isFinal()) {
                return null;
            }

            $business = SpaBusiness::find($session->spa_business_id);
            $method = $session->paymentMethod;
            $tokenId = $x['payment_token_id'] ?? null;
            if ($tokenId && $session->save_method) {
                $method = $this->storeToken($business, $tokenId, $session->purpose === 'save_method', $x['channel_code'] ?? null);
            }
            $channel = $method?->type ?? $this->typeFromChannel($x['channel_code'] ?? null) ?? 'CARD';

            $subscription = match ($session->purpose) {
                'subscribe', 'renew', 'auto_renew' => $this->startTerm($session, $business, $method),
                'upgrade' => $this->applyUpgrade($session),
                default => $this->useNewMethod($business, $method, $session->enable_auto_renew),
            };

            if ($session->purpose !== 'save_method') {
                $this->recordInvoice($session, $subscription, $method, $channel, $x['payment_request_id'] ?? $session->xendit_payment_request_id);
            }

            $session->update([
                'status' => 'completed',
                'completed_at' => now(),
                'subscription_id' => $subscription?->id ?? $session->subscription_id,
                'payment_method_id' => $method?->id ?? $session->payment_method_id,
                'xendit_payment_request_id' => $x['payment_request_id'] ?? $session->xendit_payment_request_id,
            ]);

            return [$session, $subscription, $business];
        });

        if ($done) {
            $this->notifyCompleted(...$done);
        }
    }

    // subscribe / renew / auto_renew: a new term. A renewal made before the
    // current term ends starts when it ends, so no paid days are lost.
    private function startTerm(CheckoutSession $session, SpaBusiness $business, ?BusinessPaymentMethod $method): Subscription
    {
        $previous = $this->subscriptions->findLatestForBusiness($business->id);
        $start = now();
        if ($session->purpose === 'auto_renew' && $previous?->expires_at?->isFuture()) {
            $start = $previous->expires_at->copy();
        }

        $autoRenew = $session->purpose === 'auto_renew'
            ? (bool) $previous?->auto_renew
            : ($session->enable_auto_renew && $method !== null);

        $subscription = $this->subscriptions->create([
            'spa_business_id' => $business->id,
            'subscription_plan_id' => $session->subscription_plan_id,
            'billing_cycle' => $session->billing_cycle,
            'starts_at' => $start,
            'expires_at' => $session->billing_cycle === 'Yearly' ? $start->copy()->addYear() : $start->copy()->addMonth(),
            'auto_renew' => $autoRenew,
            'payment_method_id' => $autoRenew ? ($method?->id ?? $previous?->payment_method_id) : null,
            'status' => 'Active',
        ]);

        // The old term is settled: no more renewal attempts on it.
        if ($previous && $previous->id !== $subscription->id) {
            $previous->update(['auto_renew' => false, 'renewal_failure_reason' => null]);
        }

        return $subscription;
    }

    private function applyUpgrade(CheckoutSession $session): ?Subscription
    {
        $subscription = Subscription::with('plan')->find($session->subscription_id);
        if (! $subscription) {
            Log::warning('checkout.upgrade_without_subscription', ['session' => $session->uuid]);

            return null;
        }
        $session->setAttribute('meta', ['from_plan' => $subscription->plan?->name]);
        $this->planSwitch->applyUpgrade($subscription, $session->subscription_plan_id);

        return $subscription;
    }

    // save_method: the new method becomes the default, and what a running
    // auto-renewal charges from now on (or turns auto-renew on, if asked).
    private function useNewMethod(SpaBusiness $business, ?BusinessPaymentMethod $method, bool $enableAutoRenew): ?Subscription
    {
        $live = $this->subscriptions->findActiveOrInGraceForBusiness($business->id, \App\Models\SystemSetting::current()->subscription_grace_period_days);
        if ($live && $method && ($live->auto_renew || ($enableAutoRenew && ! $live->isEndingByChoice()))) {
            $live->update(['auto_renew' => true, 'payment_method_id' => $method->id, 'renewal_failure_reason' => null]);
        }

        return $live;
    }

    private function recordInvoice(CheckoutSession $session, ?Subscription $subscription, ?BusinessPaymentMethod $method, string $channel, ?string $paymentRequestId): void
    {
        $plan = SubscriptionPlan::find($session->subscription_plan_id);
        $remarks = match ($session->purpose) {
            'upgrade' => 'Upgrade: ' . ($session->meta['from_plan'] ?? 'previous plan') . ' → ' . ($plan?->name ?? 'new plan'),
            'auto_renew' => ($plan?->name ?? 'Plan') . " Plan Renewal ({$session->billing_cycle})",
            default => ($plan?->name ?? 'Plan') . " Plan Subscription ({$session->billing_cycle})",
        };

        $billing = $this->billings->create([
            'subscription_id' => $subscription?->id,
            'spa_business_id' => $session->spa_business_id,
            'billing_type' => 'Subscription',
            'billing_number' => $this->billings->generateBillingNumber(),
            'amount' => $session->amount,
            'vat_amount' => $session->vat_amount,
            'status' => 'Paid',
            'issued_at' => now(),
            'paid_at' => now(),
            'remarks' => $remarks,
        ]);

        Payment::create([
            'billing_id' => $billing->id,
            'spa_business_id' => $session->spa_business_id,
            'payment_method' => match ($channel) {
                'GCASH' => 'GCash',
                'MAYA' => 'Maya',
                default => $method?->brand && str_contains(strtoupper((string) $method->brand), 'DEBIT') ? 'Debit Card' : 'Credit Card',
            },
            'gateway_provider' => 'Xendit',
            'gateway_reference' => $paymentRequestId,
            'reference_number' => $session->reference_id,
            'amount' => $session->amount,
            'payment_status' => 'Paid',
            'paid_at' => now(),
        ]);
    }

    private function notifyCompleted(CheckoutSession $session, ?Subscription $subscription, SpaBusiness $business): void
    {
        if ($session->purpose === 'save_method') {
            return;
        }

        $admins = $this->admins->allAdministrators();
        $subscription?->loadMissing('plan');

        if (in_array($session->purpose, ['subscribe', 'renew'], true) && $subscription?->plan) {
            $this->notifications->subscriptionActivated($business, $subscription->plan, $subscription->billing_cycle, $admins);
        }
        if ($session->purpose === 'auto_renew' && $subscription) {
            $this->notifications->subscriptionAutoRenewed($subscription, (float) $session->amount);
        }
        $this->notifications->paymentReceived($business, (float) $session->amount, $admins);
    }

    // ── Saved methods & address ──────────────────────────────────────────

    // A Xendit payment token → a saved method (card brand/last 4, or the
    // e-wallet). The first one, or one the owner just added, is the default.
    private function storeToken(SpaBusiness $business, string $tokenId, bool $makeDefault, ?string $channelHint = null): BusinessPaymentMethod
    {
        $existing = BusinessPaymentMethod::withTrashed()->where('xendit_payment_token_id', $tokenId)->first();
        if ($existing) {
            $existing->trashed() && $existing->restore();

            return $existing;
        }

        $details = [];
        try {
            $details = $this->xendit->getPaymentToken($tokenId);
        } catch (XenditRequestException $e) {
            Log::warning('checkout.token_lookup_failed', ['token' => $tokenId, 'error' => $e->getMessage()]);
        }

        $type = $this->typeFromChannel($details['channel_code'] ?? $channelHint) ?? 'CARD';
        $card = $details['channel_properties']['card_details'] ?? $details['token_details'] ?? [];
        $last4 = null;
        if (! empty($card['masked_card_number'])) {
            $last4 = substr(preg_replace('/\D/', '', $card['masked_card_number']), -4) ?: null;
        }
        $brand = $type === 'CARD' ? ($card['network'] ?? 'Card') : null;
        $account = $details['token_details']['account_name'] ?? null;

        $label = match ($type) {
            'GCASH' => 'GCash' . ($account ? " · {$account}" : ''),
            'MAYA' => 'Maya' . ($account ? " · {$account}" : ''),
            default => ucfirst(strtolower((string) $brand)) . ($last4 ? " •••• {$last4}" : ''),
        };

        $hasDefault = BusinessPaymentMethod::where('spa_business_id', $business->id)->where('is_default', true)->exists();
        if ($makeDefault) {
            BusinessPaymentMethod::where('spa_business_id', $business->id)->update(['is_default' => false]);
        }

        return BusinessPaymentMethod::create([
            'spa_business_id' => $business->id,
            'type' => $type,
            'xendit_payment_token_id' => $tokenId,
            'label' => $label,
            'brand' => $brand,
            'last4' => $last4,
            'expiry_month' => isset($card['expiry_month']) ? (int) $card['expiry_month'] : null,
            'expiry_year' => isset($card['expiry_year']) ? (int) $card['expiry_year'] : null,
            'status' => 'active',
            'is_default' => $makeDefault || ! $hasDefault,
        ]);
    }

    public function methodsFor(SpaBusiness $business): array
    {
        return BusinessPaymentMethod::where('spa_business_id', $business->id)
            ->where('status', 'active')
            ->orderByDesc('is_default')->latest()
            ->get()->map->present()->all();
    }

    // The saved address, or one pre-filled from the business profile.
    public function addressFor(SpaBusiness $business): array
    {
        $business->loadMissing(['billingAddress', 'owner']);
        if ($business->billingAddress) {
            return $business->billingAddress->present();
        }

        $owner = $business->owner;

        return [
            'full_name' => trim(($owner?->first_name ?? '') . ' ' . ($owner?->last_name ?? '')) ?: ($business->legal_name ?? ''),
            'email' => $business->business_email ?: ($owner?->email ?? ''),
            'phone' => $business->business_phone ?? '',
            'line1' => $business->head_office_address ?? '',
            'line2' => '',
            'city' => '',
            'province' => '',
            'postal_code' => '',
            'country' => 'PH',
            'is_business_purchase' => false,
            'business_name' => $business->legal_name ?: ($business->business_name ?? ''),
            'tin' => '',
            'is_saved' => false,
        ];
    }

    private function saveAddress(SpaBusiness $business, array $address): BusinessBillingAddress
    {
        $isBusiness = (bool) ($address['is_business_purchase'] ?? false);

        return BusinessBillingAddress::updateOrCreate(['spa_business_id' => $business->id], [
            'full_name' => trim($address['full_name']),
            'email' => trim($address['email']),
            'phone' => $address['phone'] ?? null,
            'line1' => trim($address['line1']),
            'line2' => $address['line2'] ?? null,
            'city' => trim($address['city']),
            'province' => $address['province'] ?? null,
            'postal_code' => trim($address['postal_code']),
            'country' => strtoupper($address['country'] ?? 'PH'),
            'is_business_purchase' => $isBusiness,
            'business_name' => $isBusiness ? ($address['business_name'] ?? null) : null,
            'tin' => $isBusiness ? ($address['tin'] ?? null) : null,
        ]);
    }

    // Xendit keeps one customer per business; the first session creates it.
    private function customerFields(SpaBusiness $business, BusinessBillingAddress $address): array
    {
        if ($business->xendit_customer_id) {
            return ['customer_id' => $business->xendit_customer_id];
        }

        $parts = preg_split('/\s+/', trim($address->full_name), 2);

        return ['customer' => array_filter([
            'reference_id' => 'servora-business-' . $business->uuid,
            'type' => 'INDIVIDUAL',
            'email' => $address->email,
            'mobile_number' => self::e164($address->phone),
            'individual_detail' => array_filter(['given_names' => $parts[0] ?: 'Servora', 'surname' => $parts[1] ?? null]),
        ])];
    }

    // 09171234567 / 639171234567 / +639171234567 → +639171234567 (else null).
    public static function e164(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return match (true) {
            strlen($digits) === 11 && str_starts_with($digits, '09') => '+63' . substr($digits, 1),
            strlen($digits) === 12 && str_starts_with($digits, '639') => '+' . $digits,
            default => null,
        };
    }

    // Xendit Components only run on an HTTPS page it was told about. Uses the
    // page's own origin when it's our frontend over HTTPS.
    private function origin(?string $requestOrigin): string
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $frontendHost = parse_url($frontend, PHP_URL_HOST);
        $candidate = $requestOrigin && parse_url($requestOrigin, PHP_URL_HOST) === $frontendHost ? rtrim($requestOrigin, '/') : $frontend;

        if (! str_starts_with($candidate, 'https://')) {
            abort(422, 'The checkout must be opened over HTTPS for the secure payment form to load.');
        }

        return $candidate;
    }

    private static function isPublicOrigin(string $origin): bool
    {
        $host = (string) parse_url($origin, PHP_URL_HOST);

        return $host !== 'localhost' && ! str_ends_with($host, '.localhost') && ! filter_var($host, FILTER_VALIDATE_IP);
    }

    private function typeFromChannel(?string $channel): ?string
    {
        return match (strtoupper((string) $channel)) {
            'GCASH' => 'GCASH',
            'PAYMAYA', 'MAYA' => 'MAYA',
            'CARDS', 'CARD', 'CREDIT_CARD' => 'CARD',
            default => null,
        };
    }

    private function failureMessage(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'INSUFFICIENT_BALANCE', 'INSUFFICIENT_FUNDS' => 'Not enough balance on the payment method.',
            'CARD_DECLINED', 'DECLINED_BY_ISSUER', 'DECLINED_BY_PROCESSOR', 'ISSUER_SUSPECT_FRAUD' => 'The card was declined.',
            'EXPIRED_CARD', 'INVALID_CARD' => 'The card has expired or is no longer valid.',
            'ACCOUNT_ACCESS_BLOCKED', 'TOKEN_NOT_ACTIVE', 'PAYMENT_TOKEN_NOT_ACTIVE', 'ACCOUNT_NOT_ACTIVATED' => 'The saved account can no longer be charged. Please add it again.',
            default => 'The payment didn\'t go through.',
        };
    }

    // ── Presenting ───────────────────────────────────────────────────────

    public function present(CheckoutSession $session): array
    {
        return [
            'uuid' => $session->uuid,
            'purpose' => $session->purpose,
            'status' => $session->status,
            'failure_reason' => $session->failure_reason,
            'amount' => (float) $session->amount,
            'vat_amount' => (float) $session->vat_amount,
            'billing_cycle' => $session->billing_cycle,
            'plan' => $session->plan ? ['uuid' => $session->plan->uuid, 'name' => $session->plan->name] : null,
            'payment_method' => $session->paymentMethod?->present(),
            'completed_at' => $session->completed_at,
        ];
    }

    private function businessOf(User $owner): SpaBusiness
    {
        $business = $this->businesses->findByOwnerId($owner->id);
        abort_if(! $business, 422, 'No spa business found for this account.');

        return $business;
    }
}
