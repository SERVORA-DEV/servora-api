<?php

namespace App\Service;

use App\Models\CheckoutSession;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Repository\SubscriptionRepository;
use App\Repository\SpaBusinessRepository;
use App\Repository\BillingRepository;
use App\Repository\PaymentRepository;
use App\Repository\Business\AccountRepository;
use App\Repository\System\AdminUsersRepository;
use App\Repository\System\SubscriptionPlanRepository;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\BillingResource;
use App\Service\NotificationService;
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
    private AccountRepository $accountRepository;
    private XenditService $xenditService;
    private AdminUsersRepository $adminUsersRepository;
    private NotificationService $notificationService;

    public function __construct(
        SubscriptionRepository $subscriptionRepository,
        SpaBusinessRepository $businessRepository,
        SubscriptionPlanRepository $subscriptionPlanRepository,
        BillingRepository $billingRepository,
        PaymentRepository $paymentRepository,
        AccountRepository $accountRepository,
        XenditService $xenditService,
        AdminUsersRepository $adminUsersRepository,
        NotificationService $notificationService,
        private PlanChangeService $planChangeService,
        private PlanSwitchService $planSwitchService,
        private CheckoutService $checkoutService,
    ) {
        $this->subscriptionRepository = $subscriptionRepository;
        $this->businessRepository = $businessRepository;
        $this->subscriptionPlanRepository = $subscriptionPlanRepository;
        $this->billingRepository = $billingRepository;
        $this->paymentRepository = $paymentRepository;
        $this->accountRepository = $accountRepository;
        $this->xenditService = $xenditService;
        $this->adminUsersRepository = $adminUsersRepository;
        $this->notificationService = $notificationService;
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
        // Same self-heal for Servora's own checkout: an owner who approved in
        // GCash / Maya but never landed back on the return page (or whose
        // webhook couldn't reach us) is settled just by opening this page.
        CheckoutSession::where('spa_business_id', $business->id)
            ->where('status', 'pending')
            ->where('purpose', '!=', 'auto_renew')
            ->where('created_at', '>', now()->subDay())
            ->latest()->take(3)->get()
            ->each(fn ($session) => $this->checkoutService->refresh($session));

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
                'payment_methods' => $this->checkoutService->methodsFor($business),
                'billing_address' => $this->checkoutService->addressFor($business),
            ], 200);
        }

        // Same grace window EnsureBusinessSubscribed uses to keep the
        // dashboard reachable past expires_at — surfaced here so the owner
        // sees they're in it, not just silently kept logged in.
        $graceDays = SystemSetting::current()->subscription_grace_period_days;
        // No grace for a subscription the owner chose to let end (declined
        // an updated plan — see PlanChangeService).
        $isLapsed = $subscription->status === 'Active'
            && ! $subscription->isEndingByChoice()
            && $subscription->expires_at !== null
            && $subscription->expires_at->isPast();
        // diffInDays returns a float (fractional days) — round for a clean
        // whole-day comparison/display.
        $daysSinceExpiry = $isLapsed ? (int) round($subscription->expires_at->diffInDays(now())) : 0;
        $inGracePeriod = $isLapsed && $daysSinceExpiry <= $graceDays;
        $graceDaysRemaining = $inGracePeriod ? max(0, $graceDays - $daysSinceExpiry) : null;

        return response()->json([
            'has_subscription' => true,
            'subscription' => new SubscriptionResource($subscription),
            'billings' => $billings,
            // Current usage against the plan's limits — counted live off
            // branches/accounts rather than stored on the subscription row,
            // so it stays correct as branches/staff are added or removed
            // mid-cycle.
            'branches_used' => $business->branches()->count(),
            'staff_used' => $this->accountRepository->countForBusiness($business->id),
            'in_grace_period' => $inGracePeriod,
            'grace_days_remaining' => $graceDaysRemaining,
            // Admin updated this subscription's plan — the owner's
            // accept/cancel prompt (null when there's nothing to answer).
            'plan_change' => $this->planChangeService->present($subscription),
            // The owner's own downgrade waiting for the next billing, and the
            // admin's Subscription Policy deadlines for this term.
            'scheduled_change' => $this->planSwitchService->presentScheduled($subscription),
            'plan_policy' => $this->planSwitchService->presentPolicy($subscription),
            // Saved card / GCash / Maya, the billing address, and how
            // auto-renewal stands (CheckoutService, SubscriptionRenewalService).
            'payment_methods' => $this->checkoutService->methodsFor($business),
            'billing_address' => $this->checkoutService->addressFor($business),
            'auto_renew' => [
                'enabled' => (bool) $subscription->auto_renew,
                'payment_method' => $subscription->auto_renew ? $subscription->paymentMethod?->present() : null,
                'failure_reason' => $subscription->renewal_failure_reason,
                'last_attempt_at' => $subscription->last_renewal_attempt_at,
            ],
        ], 200);
    }

    // GET /business/subscription/capacity — the plan's branch/account limits
    // and how many are used, for the Branches and Accounts pages. Same
    // "latest subscription" as getCurrentSubscription() above, without its
    // payment self-heal and billing history, so it answers in a few queries.
    public function capacity(User $user)
    {
        $business = $this->businessRepository->findByOwnerId($user->id);
        if (! $business) {
            return response()->json(['has_subscription' => false, 'plan' => null, 'branches_used' => 0, 'accounts_used' => 0]);
        }

        $subscription = $this->subscriptionRepository->findLatestForBusiness($business->id);
        $plan = $subscription?->plan;

        return response()->json([
            'has_subscription' => (bool) $subscription,
            'status' => $subscription?->status,
            'plan' => $plan ? [
                'name' => $plan->name,
                'max_branches' => (int) $plan->max_branches,
                'max_user_accounts' => (int) $plan->max_user_accounts,
            ] : null,
            'branches_used' => $business->branches()->count(),
            'accounts_used' => $this->accountRepository->countForBusiness($business->id),
        ]);
    }

    // ── Upgrade / downgrade (PlanSwitchService) ─────────────────────────

    // GET business/subscription/change/quote — what switching to a plan
    // would cost and when it would apply, before the owner commits.
    public function quotePlanChange(User $user, array $payload)
    {
        [$subscription, $plan, $error] = $this->liveSubscriptionAndPlan($user, $payload['subscription_plan_uuid']);
        if ($error) {
            return $error;
        }

        return response()->json(['data' => $this->planSwitchService->quote($subscription, $plan, $payload['billing_cycle'])], 200);
    }

    // POST business/subscription/change — an upgrade starts a Xendit invoice
    // for the difference (applied once paid, like a new subscription); a
    // downgrade is scheduled for the next billing, nothing charged now.
    public function changePlan(User $user, array $payload)
    {
        [$subscription, $plan, $error] = $this->liveSubscriptionAndPlan($user, $payload['subscription_plan_uuid']);
        if ($error) {
            return $error;
        }

        $quote = $this->planSwitchService->quote($subscription, $plan, $payload['billing_cycle']);
        if (! $quote['allowed']) {
            return response()->json(['message' => $quote['blocked_reason'], 'data' => $quote], 422);
        }

        if ($quote['type'] === 'downgrade') {
            $this->planSwitchService->scheduleDowngrade($subscription, $plan, $payload['billing_cycle']);

            return response()->json([
                'type' => 'downgrade',
                'message' => "You'll move to the {$plan->name} plan on {$subscription->expires_at->format('F j, Y')}. You keep your current plan until then.",
                'scheduled_change' => $this->planSwitchService->presentScheduled($subscription->fresh()),
                'over_limits' => $quote['over_limits'],
            ], 200);
        }

        // Upgrades pay the difference on Servora's checkout (CheckoutService,
        // purpose upgrade), which re-checks the same PlanSwitchService rules.
        return response()->json([
            'type' => 'upgrade',
            'amount_due' => $quote['amount_due'],
            'checkout_url' => '/business/checkout?purpose=upgrade&plan=' . $plan->uuid . '&cycle=' . ($subscription->billing_cycle ?? 'Monthly'),
        ], 200);
    }

    // DELETE business/subscription/change — keep the current plan after all.
    public function cancelScheduledPlanChange(User $user)
    {
        $business = $this->businessRepository->findByOwnerId($user->id);
        $subscription = $business ? $this->subscriptionRepository->findActiveForBusiness($business->id) : null;

        if (! $subscription || ! $subscription->scheduled_plan_id) {
            return response()->json(['message' => 'There is no plan change scheduled.'], 422);
        }

        $this->planSwitchService->cancelScheduled($subscription);

        return response()->json(['message' => "You'll stay on your current plan."], 200);
    }

    // [subscription, plan, null] or [null, null, error response].
    private function liveSubscriptionAndPlan(User $user, string $planUuid): array
    {
        $business = $this->businessRepository->findByOwnerId($user->id);
        if (! $business) {
            return [null, null, response()->json(['message' => 'No spa business found for this account.'], 422)];
        }

        $subscription = $this->subscriptionRepository->findActiveForBusiness($business->id);
        if (! $subscription) {
            return [null, null, response()->json(['message' => 'You have no active plan to change. Choose a plan to subscribe.'], 422)];
        }

        return [$subscription, $this->subscriptionPlanRepository->findByUuid($planUuid), null];
    }

    public function respondToPlanChange(User $user, string $decision)
    {
        return $this->planChangeService->respond($user, $decision);
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
        // Paying now happens on Servora's own checkout (CheckoutService) —
        // card, GCash or Maya, with a billing address and VAT on the invoice.
        // The hosted-invoice flow below is kept only so an invoice already
        // opened before this change can still be confirmed.
        return response()->json([
            'message' => 'Choose your plan and pay on the checkout page.',
            'checkout_url' => '/business/checkout?purpose=subscribe&plan=' . $payload['subscription_plan_uuid'] . '&cycle=' . $payload['billing_cycle'],
        ], 409);
    }

    // Retired hosted-invoice purchase — see createSubscription.
    private function createHostedInvoiceSubscription(User $user, array $payload)
    {
        $business = $this->businessRepository->findByOwnerId($user->id);

        if (! $business) {
            return response()->json([
                'message' => 'No spa business found for this account.',
            ], 422);
        }

        // One live subscription per business at a time — upgrading/downgrading
        // an existing plan is a separate flow (changes the current
        // subscription's plan in place) and isn't this endpoint's job; this
        // only stops a *second* subscription from being purchased alongside
        // one that hasn't expired yet.
        $activeSubscription = $this->subscriptionRepository->findActiveForBusiness($business->id);

        if ($activeSubscription) {
            return response()->json([
                'message' => $activeSubscription->expires_at
                    ? "You already have an active subscription, valid until {$activeSubscription->expires_at->format('F j, Y')}. It must expire before you can subscribe to a new plan."
                    : 'You already have an active subscription. It must expire before you can subscribe to a new plan.',
            ], 422);
        }

        $plan = $this->subscriptionPlanRepository->findByUuid($payload['subscription_plan_uuid']);

        // A smaller plan (e.g. a downgrade taking effect at renewal) can only
        // be bought once the business fits inside its limits.
        if ($overLimits = $this->planSwitchService->overLimits($business, $plan)) {
            return response()->json([
                'message' => implode(' ', $overLimits) . ' Remove branches or accounts first, or pick a bigger plan.',
                'over_limits' => $overLimits,
            ], 422);
        }

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
        // Xendit retries and can deliver the same callback twice at once.
        // Without a lock both deliveries could read the pending intent before
        // either clears it, and create two subscriptions and two billings.
        // The second delivery waits here, then finds the intent gone and
        // no-ops below.
        return Cache::lock('activate-subscription:' . $referenceId, 30)
            ->block(10, fn () => $this->activateSubscriptionFromPaymentLocked(
                $referenceId, $invoiceId, $paymentMethod, $paymentChannel, $paidAmount,
            ));
    }

    private function activateSubscriptionFromPaymentLocked(
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

        if (($intent['type'] ?? 'new') === 'upgrade') {
            return $this->activateUpgradeFromPayment($intent, $referenceId, $invoiceId, $paymentMethod, $paymentChannel, $paidAmount);
        }

        $subscription = DB::transaction(function () use ($intent, $invoiceId, $paymentMethod, $paymentChannel, $paidAmount, $referenceId) {
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
                // Kept on the invoice, since a later upgrade changes the
                // subscription's plan (BillingResource::description).
                'remarks' => (SubscriptionPlan::find($intent['subscription_plan_id'])?->name ?? 'Plan') . " Plan Subscription ({$intent['billing_cycle']})",
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

            return $subscription;
        });

        Cache::forget(self::PENDING_CACHE_PREFIX . $referenceId);
        Cache::forget(self::PENDING_BUSINESS_CACHE_PREFIX . $intent['spa_business_id']);

        // Admin-facing notifications for a real, webhook-confirmed payment —
        // one subscription-started fact and one payment-received fact.
        $subscription->loadMissing(['business', 'plan']);
        if ($subscription->business && $subscription->plan) {
            $admins = $this->adminUsersRepository->allAdministrators();
            $this->notificationService->subscriptionActivated($subscription->business, $subscription->plan, $subscription->billing_cycle, $admins);
            $this->notificationService->paymentReceived($subscription->business, $paidAmount, $admins);
        }

        return true;
    }

    // A paid upgrade: the plan switches now (PlanSwitchService::applyUpgrade),
    // and the difference is recorded as a Paid billing on the same
    // subscription — so it counts toward "paid this term" for any further
    // upgrade. Same idempotency as a new subscription: the intent is dropped
    // once applied, so the webhook and the confirm fallback can't both apply.
    private function activateUpgradeFromPayment(array $intent, string $referenceId, string $invoiceId, ?string $paymentMethod, ?string $paymentChannel, float $paidAmount): bool
    {
        $applied = DB::transaction(function () use ($intent, $referenceId, $invoiceId, $paymentMethod, $paymentChannel, $paidAmount) {
            $subscription = Subscription::with('plan')->lockForUpdate()->find($intent['subscription_id']);
            if (! $subscription) {
                Log::warning('xendit.upgrade.subscription_missing', ['reference_id' => $referenceId]);

                return null;
            }

            $billing = $this->billingRepository->create([
                'subscription_id' => $subscription->id,
                'spa_business_id' => $intent['spa_business_id'],
                'billing_type' => 'Subscription',
                'billing_number' => $this->billingRepository->generateBillingNumber(),
                'amount' => $intent['amount'],
                'status' => 'Paid',
                'issued_at' => now(),
                'paid_at' => now(),
                'remarks' => 'Upgrade: ' . ($intent['from_plan_name'] ?? 'previous plan') . ' → ' . ($intent['to_plan_name'] ?? 'new plan'),
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

            $this->planSwitchService->applyUpgrade($subscription, $intent['subscription_plan_id']);

            return $subscription;
        });

        Cache::forget(self::PENDING_CACHE_PREFIX . $referenceId);
        Cache::forget(self::PENDING_BUSINESS_CACHE_PREFIX . $intent['spa_business_id']);

        if ($applied?->business) {
            $this->notificationService->paymentReceived($applied->business, $paidAmount, $this->adminUsersRepository->allAdministrators());
        }

        return (bool) $applied;
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