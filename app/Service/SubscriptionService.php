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

        Cache::put(self::PENDING_CACHE_PREFIX . $referenceId, [
            'spa_business_id' => $business->id,
            'subscription_plan_id' => $plan->id,
            'billing_cycle' => $billingCycle,
            'amount' => $amount,
        ], now()->addHour());

        $frontendUrl = config('app.frontend_url');

        $invoice = $this->xenditService->createInvoice(
            $referenceId,
            (float) $amount,
            "{$plan->name} Plan Subscription ({$billingCycle})",
            $frontendUrl . '/payment/success',
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

        return true;
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