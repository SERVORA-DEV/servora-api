<?php

namespace App\Service;

use App\Models\User;
use App\Repository\SubscriptionRepository;
use App\Repository\SpaBusinessRepository;
use App\Repository\BillingRepository;
use App\Repository\PaymentRepository;
use App\Repository\System\SubscriptionPlanRepository;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\BillingResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionService
{
    // Payment intent is parked here between "payment link created" and
    // "Xendit webhook confirms it" — nothing is written to subscriptions/
    // billings/payments until the webhook proves the money actually moved.
    private const PENDING_CACHE_PREFIX = 'xendit_subscription_intent:';

    // Mirrors the same intent, keyed by business instead of reference_id, so
    // getCurrentSubscription() can self-check "does this business have an
    // unconfirmed payment" without needing the frontend to hand back a
    // reference_id at all. That hand-off (sessionStorage across a cross-site
    // redirect to Xendit and back, or the redirect URL's query string) is a
    // real point of failure in some browsers/environments — this makes the
    // page you'd naturally check after paying self-heal regardless.
    private const PENDING_BUSINESS_CACHE_PREFIX = 'xendit_subscription_pending_business:';

    private SubscriptionRepository $subscriptionRepository;
    private SpaBusinessRepository $businessRepository;
    private SubscriptionPlanRepository $subscriptionPlanRepository;
    private BillingRepository $billingRepository;
    private PaymentRepository $paymentRepository;
    private XenditService $xenditService;

    public function __construct(
        SubscriptionRepository $subscriptionRepository,
        SpaBusinessRepository $businessRepository,
        SubscriptionPlanRepository $subscriptionPlanRepository,
        BillingRepository $billingRepository,
        PaymentRepository $paymentRepository,
        XenditService $xenditService,
    ) {
        $this->subscriptionRepository = $subscriptionRepository;
        $this->businessRepository = $businessRepository;
        $this->subscriptionPlanRepository = $subscriptionPlanRepository;
        $this->billingRepository = $billingRepository;
        $this->paymentRepository = $paymentRepository;
        $this->xenditService = $xenditService;
    }

    public function listSubscription(int $perPage = 15)
    {
        $collection = $this->subscriptionRepository->paginate($perPage);
        return SubscriptionResource::collection($collection);
    }

    // GET /business/subscription for the logged-in business owner — not a
    // paginated admin listing, but "does my business have a subscription,
    // and if so what does it look like". Returns 200 with
    // has_subscription: false (never a 404) when there isn't one yet, so
    // the dashboard can render an empty state instead of treating a brand
    // new business as an error.
    public function getCurrentSubscription(User $user)
    {
        $business = $this->businessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json([
                'has_subscription' => false,
                'message' => 'No spa business found for this account.',
                'billings' => [],
            ], 200);
        }

        $this->resolvePendingPaymentForBusiness($business->id);

        // Billing history is independent of the current subscription's
        // status — past invoices should stay visible even once a plan has
        // expired or been cancelled, so this is fetched regardless of
        // whether $subscription below is found.
        $billings = BillingResource::collection(
            $this->billingRepository->findForBusiness($business->id)
        );

        $subscription = $this->subscriptionRepository->findLatestForBusiness($business->id);

        if (! $subscription) {
            return response()->json([
                'has_subscription' => false,
                'message' => 'No subscription found for this business yet.',
                'billings' => $billings,
            ], 200);
        }

        return response()->json([
            'has_subscription' => true,
            'subscription' => new SubscriptionResource($subscription),
            'billings' => $billings,
        ], 200);
    }

    // Does NOT create the subscription — it creates a Xendit invoice for
    // the chosen plan and hands back the hosted checkout link. We don't ask
    // which channel (GCash/Maya/card/etc.) the customer wants up front;
    // Xendit's own invoice page lists every enabled channel and the
    // customer picks there. The subscription, billing, and payment rows
    // only get created once activateSubscriptionFromPayment() is called
    // from the Xendit webhook, so we never record a subscription that was
    // never actually paid for.
    public function createSubscription(User $user, array $payload)
    {
        $business = $this->businessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        $plan = $this->subscriptionPlanRepository->findByUuid($payload['subscription_plan_uuid']);

        $billingCycle = $payload['billing_cycle'];
        $amount = $billingCycle === 'Monthly' ? $plan->monthly_price : $plan->yearly_price;

        if (! $amount) {
            return response()->json([
                'message' => "This plan does not offer a {$billingCycle} price.",
            ], 422);
        }

        $referenceId = 'SUB-' . Str::uuid();

        // TTL must be >= the Xendit invoice's own validity window (24h,
        // since createInvoice() doesn't set invoice_duration) — otherwise a
        // customer who pays after the cache entry expires has their PAID
        // webhook arrive to find no intent to activate, and it silently
        // no-ops (see activateSubscriptionFromPayment's unknown_intent log).
        Cache::put(self::PENDING_CACHE_PREFIX . $referenceId, [
            'spa_business_id' => $business->id,
            'subscription_plan_id' => $plan->id,
            'billing_cycle' => $billingCycle,
            'amount' => $amount,
        ], now()->addDay());

        Cache::put(self::PENDING_BUSINESS_CACHE_PREFIX . $business->id, $referenceId, now()->addDay());

        $frontendUrl = config('app.frontend_url');

        // reference_id travels in the redirect URL itself, not just
        // sessionStorage — browsers with cross-site bounce-tracking
        // mitigations (Safari ITP and similar) can wipe session/local
        // storage on a page that does a short-lived redirect out to another
        // domain (Xendit) and back, which silently drops the id the success
        // page needs to confirm the payment.
        $invoice = $this->xenditService->createInvoice(
            $referenceId,
            (float) $amount,
            "{$plan->name} Plan Subscription ({$billingCycle})",
            $frontendUrl . '/payment/success?reference_id=' . $referenceId,
            $frontendUrl . '/payment/failed',
        );

        return response()->json([
            'reference_id' => $referenceId,
            'payment_url' => $invoice['invoice_url'],
            'status' => $invoice['status'],
        ], 200);
    }

    // Called from XenditWebhookController once Xendit confirms the invoice
    // was actually paid. Idempotent: if the reference_id's intent is missing
    // (already processed, or expired/unknown) it silently no-ops instead of
    // creating duplicate rows.
    public function activateSubscriptionFromPayment(
        string $referenceId,
        string $invoiceId,
        ?string $paymentMethod,
        ?string $paymentChannel,
        float $paidAmount
    ): bool {
        $intent = Cache::get(self::PENDING_CACHE_PREFIX . $referenceId);

        if (! $intent) {
            Log::warning('xendit.webhook.unknown_intent', ['reference_id' => $referenceId]);
            return false;
        }

        DB::transaction(function () use ($intent, $invoiceId, $paymentMethod, $paymentChannel, $paidAmount, $referenceId) {
            $subscription = $this->subscriptionRepository->create([
                'spa_business_id' => $intent['spa_business_id'],
                'subscription_plan_id' => $intent['subscription_plan_id'],
                'billing_cycle' => $intent['billing_cycle'],
                'starts_at' => now(),
                'expires_at' => $intent['billing_cycle'] === 'Monthly' ? now()->addMonth() : now()->addYear(),
                'auto_renew' => false,
                'status' => 'Active',
            ]);

            $billing = $this->billingRepository->create([
                'subscription_id' => $subscription->id,
                'spa_business_id' => $intent['spa_business_id'],
                'billing_type' => 'Subscription',
                'billing_number' => $this->billingRepository->generateBillingNumber(),
                'amount' => $intent['amount'],
                'status' => 'Paid',
                'issued_at' => now(),
                'paid_at' => now(),
            ]);

            $this->paymentRepository->create([
                'billing_id' => $billing->id,
                'spa_business_id' => $intent['spa_business_id'],
                'payment_method' => $this->mapChannelToPaymentMethod($paymentMethod, $paymentChannel),
                'gateway_provider' => 'Xendit',
                'gateway_reference' => $invoiceId,
                'reference_number' => $referenceId,
                'amount' => $paidAmount,
                'payment_status' => 'Paid',
                'paid_at' => now(),
            ]);
        });

        Cache::forget(self::PENDING_CACHE_PREFIX . $referenceId);
        Cache::forget(self::PENDING_BUSINESS_CACHE_PREFIX . $intent['spa_business_id']);

        return true;
    }

    // Self-healing check run on every getCurrentSubscription() call: if this
    // business has an unconfirmed payment on file, ask Xendit directly
    // whether it actually went through and activate it if so. Makes simply
    // loading the subscription page (or the Plans page, which also calls
    // getCurrentSubscription) sufficient to pick up a payment even if the
    // webhook never arrived and the /payment/success page's own confirm
    // call never fired (lost sessionStorage, closed tab, etc).
    private function resolvePendingPaymentForBusiness(int $businessId): void
    {
        $referenceId = Cache::get(self::PENDING_BUSINESS_CACHE_PREFIX . $businessId);

        if (! $referenceId) {
            return;
        }

        $intent = Cache::get(self::PENDING_CACHE_PREFIX . $referenceId);

        if (! $intent) {
            Cache::forget(self::PENDING_BUSINESS_CACHE_PREFIX . $businessId);
            return;
        }

        try {
            $invoice = $this->xenditService->getInvoiceByExternalId($referenceId);
        } catch (\Throwable $e) {
            Log::warning('xendit.pending_check.failed', ['reference_id' => $referenceId, 'error' => $e->getMessage()]);
            return;
        }

        if (! $invoice || $invoice['status'] !== 'PAID') {
            return;
        }

        $this->activateSubscriptionFromPayment(
            $referenceId,
            $invoice['id'],
            $invoice['payment_method'] ?? null,
            $invoice['payment_channel'] ?? null,
            (float) ($invoice['paid_amount'] ?? $invoice['amount'] ?? 0),
        );

        Log::info('xendit.pending_check.activated', ['reference_id' => $referenceId]);
    }

    // Fallback for when Xendit's webhook can't reach us (e.g. local dev with
    // no public tunnel) — called from the frontend's /payment/success page
    // instead of waiting on the callback. Safe to call alongside the real
    // webhook: activateSubscriptionFromPayment() only acts while the intent
    // is still cached, so whichever of the two runs first "wins" and the
    // other silently no-ops instead of creating duplicate rows.
    public function confirmPendingPayment(User $user, string $referenceId): array
    {
        Log::info('xendit.confirm.requested', ['reference_id' => $referenceId]);

        $intent = Cache::get(self::PENDING_CACHE_PREFIX . $referenceId);

        if (! $intent) {
            $business = $this->businessRepository->findByOwnerId($user->id);
            $subscription = $business ? $this->subscriptionRepository->findLatestForBusiness($business->id) : null;

            Log::info('xendit.confirm.no_pending_intent', [
                'reference_id' => $referenceId,
                'found_subscription' => (bool) $subscription,
            ]);

            return [
                'activated' => (bool) $subscription,
                'status' => $subscription->status ?? 'unknown',
            ];
        }

        $invoice = $this->xenditService->getInvoiceByExternalId($referenceId);

        if (! $invoice || $invoice['status'] !== 'PAID') {
            Log::info('xendit.confirm.not_yet_paid', [
                'reference_id' => $referenceId,
                'invoice_status' => $invoice['status'] ?? null,
            ]);

            return [
                'activated' => false,
                'status' => $invoice['status'] ?? 'PENDING',
            ];
        }

        $this->activateSubscriptionFromPayment(
            $referenceId,
            $invoice['id'],
            $invoice['payment_method'] ?? null,
            $invoice['payment_channel'] ?? null,
            (float) ($invoice['paid_amount'] ?? $invoice['amount'] ?? 0),
        );

        Log::info('xendit.confirm.activated', ['reference_id' => $referenceId]);

        return ['activated' => true, 'status' => 'Active'];
    }

    // The customer can now land on any channel Xendit offers on its hosted
    // invoice page, not just the GCash/Maya we used to hardcode — this maps
    // Xendit's broad `payment_method` category plus the specific
    // `payment_channel` down to the fixed set the payments table accepts.
    private function mapChannelToPaymentMethod(?string $paymentMethod, ?string $paymentChannel): string
    {
        $channel = strtoupper($paymentChannel ?? '');
        $method = strtoupper($paymentMethod ?? '');

        if (str_contains($channel, 'GCASH')) {
            return 'GCash';
        }

        if (str_contains($channel, 'PAYMAYA') || str_contains($channel, 'MAYA')) {
            return 'Maya';
        }

        return match ($method) {
            'CREDIT_CARD' => 'Credit Card',
            'DEBIT_CARD' => 'Debit Card',
            'BANK_TRANSFER', 'DIRECT_DEBIT', 'RETAIL_OUTLET' => 'Online Banking',
            'QR_CODE' => 'QR Code',
            default => 'Other',
        };
    }

    public function getSubscription(string $uuid)
    {
        $model = $this->subscriptionRepository->findByUuid($uuid);
        return new SubscriptionResource($model);
    }

    public function getSubscriptionByField(string $field, $value)
    {
        $model = $this->subscriptionRepository->findByField($field, $value);
        return new SubscriptionResource($model);
    }

    public function updateSubscription(string $uuid, array $payload)
    {
        $model = $this->subscriptionRepository->update($uuid, $payload);
        return new SubscriptionResource($model);
    }

    public function deleteSubscription(string $uuid)
    {
        $this->subscriptionRepository->delete($uuid);
        return true;
    }

    public function restoreSubscription(string $uuid)
    {
        $model = $this->subscriptionRepository->restore($uuid);
        return new SubscriptionResource($model);
    }
}