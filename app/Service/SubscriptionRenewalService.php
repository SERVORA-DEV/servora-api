<?php

namespace App\Service;

use App\Models\BusinessPaymentMethod;
use App\Models\CheckoutSession;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Repository\SubscriptionRepository;
use Illuminate\Support\Str;

// Auto-renewal: when a subscription with auto-renew on is due, charge the
// owner's saved card / GCash / Maya for the next term (CheckoutService does
// the charge and the bookkeeping). Runs from the hourly
// subscriptions:auto-renew command.
//
//  - Due = within a day of expires_at, or already past it but still inside
//    the grace period (so a failed charge is retried daily until access
//    would end anyway).
//  - The next term is on the plan the owner chose to move to (a scheduled
//    downgrade), else the admin's updated version they didn't decline, else
//    the same plan — the same order a manual renewal follows.
//  - A plan the business no longer fits (over branch/account limits) isn't
//    charged; the owner is told why.
class SubscriptionRenewalService
{
    public function __construct(
        private CheckoutService $checkout,
        private SubscriptionRepository $subscriptions,
        private SpaBusinessRepository $businesses,
        private PlanSwitchService $planSwitch,
        private NotificationService $notifications,
    ) {}

    /** @return array{attempted: int, renewed: int, failed: int} */
    public function run(): array
    {
        // Charges still processing from an earlier run.
        CheckoutSession::where('purpose', 'auto_renew')->where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(2))
            ->get()->each(fn ($s) => $this->checkout->refresh($s));

        $graceDays = SystemSetting::current()->subscription_grace_period_days;
        $stats = ['attempted' => 0, 'renewed' => 0, 'failed' => 0];

        foreach ($this->due($graceDays) as $subscription) {
            $stats['attempted']++;
            $session = $this->attempt($subscription);
            $status = $session?->fresh()->status;
            $status === 'completed' && $stats['renewed']++;
            ($status === 'failed' || $session === null) && $stats['failed']++;
        }

        return $stats;
    }

    // Each business's latest subscription only, with auto-renew on and due.
    private function due(int $graceDays)
    {
        return Subscription::with(['plan', 'scheduledPlan', 'pendingPlan', 'paymentMethod', 'business.owner'])
            ->where('status', 'Active')
            ->where('auto_renew', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDay())
            ->where('expires_at', '>', now()->subDays($graceDays))
            ->where(fn ($q) => $q->whereNull('plan_change_status')->orWhere('plan_change_status', '!=', 'declined'))
            ->where(fn ($q) => $q->whereNull('last_renewal_attempt_at')->orWhere('last_renewal_attempt_at', '<', now()->subHours(20)))
            ->whereRaw('subscriptions.id = (select max(s2.id) from subscriptions s2 where s2.spa_business_id = subscriptions.spa_business_id and s2.deleted_at is null)')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('checkout_sessions')
                ->whereColumn('checkout_sessions.subscription_id', 'subscriptions.id')
                ->where('checkout_sessions.purpose', 'auto_renew')
                ->where('checkout_sessions.status', 'pending'))
            ->get();
    }

    public function attempt(Subscription $subscription): ?CheckoutSession
    {
        $subscription->update(['last_renewal_attempt_at' => now()]);

        [$plan, $cycle] = $this->renewalPlanFor($subscription);
        $method = $subscription->paymentMethod;

        $problem = match (true) {
            ! $plan => 'Your plan is no longer offered. Choose a new plan to keep your access.',
            PlanSwitchService::price($plan, $cycle) === null => "The {$plan->name} plan isn't offered with {$cycle} billing anymore. Choose a plan to renew.",
            ! $method || $method->trashed() || $method->status !== 'active' => 'There is no saved payment method to charge. Add one to keep auto-renew working.',
            $method->isExpired() => "Your saved {$method->label} has expired. Add a new payment method.",
            default => null,
        };
        if (! $problem && ($over = $this->planSwitch->overLimits($subscription->business, $plan))) {
            $problem = implode(' ', $over) . ' Remove branches or accounts, or choose a bigger plan, to renew.';
        }
        if ($problem) {
            $this->fail($subscription, $problem);

            return null;
        }

        $amount = PlanSwitchService::price($plan, $cycle);
        $session = CheckoutSession::create([
            'spa_business_id' => $subscription->spa_business_id,
            'purpose' => 'auto_renew',
            'subscription_id' => $subscription->id,
            'subscription_plan_id' => $plan->id,
            'billing_cycle' => $cycle,
            'amount' => $amount,
            'vat_amount' => CheckoutService::vatOf($amount),
            'payment_method_id' => $method->id,
            'reference_id' => 'SRV-RENEW-' . Str::uuid(),
        ]);

        $this->checkout->chargeSession($session, $method, "{$plan->name} Plan Renewal ({$cycle})");

        return $session;
    }

    // The plan and cycle the next term is on.
    public function renewalPlanFor(Subscription $subscription): array
    {
        $cycle = $subscription->billing_cycle ?? 'Monthly';

        if ($subscription->scheduledPlan) {
            return [$subscription->scheduledPlan, $subscription->scheduled_billing_cycle ?? $cycle];
        }
        if ($subscription->pendingPlan && in_array($subscription->plan_change_status, ['pending', 'accepted'], true)) {
            return [$subscription->pendingPlan, $cycle];
        }
        if ($subscription->plan?->is_active) {
            return [$subscription->plan, $cycle];
        }

        // The plan was switched off without a replacement being offered to
        // them — renew on the tier's current plan, if there is one.
        $current = $subscription->plan
            ? SubscriptionPlan::where('category', $subscription->plan->category)->where('is_active', true)->first()
            : null;

        return [$current, $cycle];
    }

    // Called by CheckoutService::markFailed for a declined renewal charge.
    public function recordFailure(CheckoutSession $session, string $reason): void
    {
        $subscription = Subscription::with(['business.owner', 'paymentMethod'])->find($session->subscription_id);
        if ($subscription) {
            $this->fail($subscription, $reason);
        }
    }

    private function fail(Subscription $subscription, string $reason): void
    {
        $subscription->update([
            'renewal_attempts' => $subscription->renewal_attempts + 1,
            'renewal_failure_reason' => $reason,
        ]);

        $this->notifications->subscriptionRenewalFailed($subscription->loadMissing('business.owner'), $reason);
    }

    // ── Owner controls ───────────────────────────────────────────────────

    // PATCH business/subscription/auto-renew.
    public function setAutoRenew(User $owner, bool $enabled)
    {
        $business = $this->businesses->findByOwnerId($owner->id);
        $graceDays = SystemSetting::current()->subscription_grace_period_days;
        $subscription = $business ? $this->subscriptions->findActiveOrInGraceForBusiness($business->id, $graceDays) : null;

        if (! $subscription) {
            return response()->json(['message' => 'You have no active plan. Choose a plan to subscribe.'], 422);
        }

        if (! $enabled) {
            $subscription->update(['auto_renew' => false]);

            return response()->json(['message' => 'Auto-renew is off. Renew manually before your plan ends.', 'auto_renew' => false]);
        }

        if ($subscription->isEndingByChoice()) {
            return response()->json(['message' => 'You chose to let this plan end, so it can\'t auto-renew. Pick a plan to continue after it ends.'], 422);
        }

        $method = BusinessPaymentMethod::where('spa_business_id', $business->id)->where('status', 'active')
            ->orderByDesc('is_default')->latest()->get()->first(fn ($m) => ! $m->isExpired());
        if (! $method) {
            return response()->json(['message' => 'Add a card, GCash or Maya account first — auto-renew charges it each billing.', 'needs_payment_method' => true], 422);
        }

        $subscription->update(['auto_renew' => true, 'payment_method_id' => $method->id, 'renewal_failure_reason' => null]);

        return response()->json([
            'message' => "Auto-renew is on. We'll charge {$method->label} on {$subscription->expires_at?->format('F j, Y')}.",
            'auto_renew' => true,
        ]);
    }
}
