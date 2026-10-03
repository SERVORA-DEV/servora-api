<?php

namespace App\Service;

use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Repository\Business\AccountRepository;
use App\Repository\System\AdminUsersRepository;
use Carbon\Carbon;

// An owner moving their live subscription to another plan, under the
// admin's Subscription Policy (Settings → Subscription Policy):
//
//  - Grace period — for `plan_change_grace_days` after the term starts
//    (subscribing or renewing; each term is its own subscription row) the
//    owner may upgrade or downgrade. After that the plan is locked until its
//    due date (expires_at), where they renew on whichever plan they like.
//  - Upgrade — the new plan costs more than they've paid for this term.
//    They pay the difference (new price − paid so far) and switch right
//    away; the billing cycle and due date stay the same, and the grace
//    period does not restart.
//  - Downgrade — anything else (a cheaper/equal plan, or another billing
//    cycle). Nothing is charged now: they keep the current plan until
//    expires_at and their next payment is for the new one. It can be taken
//    back any time before the due date, grace period or not.
//
// There's no cancellation or refund — the system handles neither.
class PlanSwitchService
{
    private const UNLIMITED = 9999;

    public function __construct(
        private AccountRepository $accountRepository,
        private AdminUsersRepository $adminUsersRepository,
        private NotificationService $notificationService,
    ) {}

    /**
     * What switching $subscription to $target on $cycle would mean.
     *
     * @return array{type: string, label: string, allowed: bool, blocked_reason: ?string,
     *   amount_due: float, paid_this_term: float, new_price: ?float, billing_cycle: string,
     *   effective_at: ?Carbon, over_limits: string[]}
     */
    public function quote(Subscription $subscription, SubscriptionPlan $target, string $cycle): array
    {
        $subscription->loadMissing(['plan', 'business']);
        $settings = SystemSetting::current();
        $currentCycle = $subscription->billing_cycle ?? 'Monthly';
        $paid = $this->paidThisTerm($subscription);

        // Upgrades keep the current cycle; a cycle change always waits for
        // the next billing.
        $priceNow = self::price($target, $currentCycle);
        $isUpgrade = $cycle === $currentCycle && $priceNow !== null && $priceNow > $paid;

        $quote = [
            'type' => $isUpgrade ? 'upgrade' : 'downgrade',
            'label' => $this->label($subscription, $target, $cycle, $isUpgrade),
            'allowed' => true,
            'blocked_reason' => null,
            'amount_due' => $isUpgrade ? round($priceNow - $paid, 2) : 0.0,
            'paid_this_term' => $paid,
            'new_price' => self::price($target, $cycle),
            'billing_cycle' => $cycle,
            'effective_at' => $isUpgrade ? now() : $subscription->expires_at,
            'over_limits' => $isUpgrade ? [] : $this->overLimits($subscription->business, $target),
        ];

        $reason = $target->isTrial()
            ? 'The free trial isn\'t a plan you can switch to.'
            : $this->blockedReason($subscription, $target, $cycle, $isUpgrade, $settings);
        if ($reason) {
            $quote['allowed'] = false;
            $quote['blocked_reason'] = $reason;
        }

        return $quote;
    }

    // Where the business is over $plan's branch/account limits, as lines to
    // show the owner (empty when it fits). A downgrade is only warned; the
    // renewal payment on that plan is refused until it fits.
    public function overLimits(?SpaBusiness $business, SubscriptionPlan $plan): array
    {
        if (! $business) {
            return [];
        }

        $lines = [];
        $branches = $business->branches()->count();
        if ($plan->max_branches < self::UNLIMITED && $branches > $plan->max_branches) {
            $lines[] = "You have {$branches} branches; the {$plan->name} plan allows {$plan->max_branches}.";
        }

        $accounts = $this->accountRepository->countForBusiness($business->id);
        if ($plan->max_user_accounts < self::UNLIMITED && $accounts > $plan->max_user_accounts) {
            $lines[] = "You have {$accounts} staff accounts; the {$plan->name} plan allows {$plan->max_user_accounts}.";
        }

        return $lines;
    }

    // Paid billings on this subscription — the first payment plus any
    // upgrades already bought this term.
    public function paidThisTerm(Subscription $subscription): float
    {
        return round((float) $subscription->billings()->where('status', 'Paid')->sum('amount'), 2);
    }

    public function scheduleDowngrade(Subscription $subscription, SubscriptionPlan $target, string $cycle): void
    {
        $subscription->loadMissing(['plan', 'business']);

        $subscription->update([
            'scheduled_plan_id' => $target->id,
            'scheduled_billing_cycle' => $cycle,
            'scheduled_at' => now(),
            // The owner's own choice supersedes an admin plan-edit prompt
            // (PlanChangeService) — the renewal is on the plan they picked.
            'pending_plan_id' => null,
            'plan_change_status' => null,
            'plan_change_responded_at' => null,
        ]);

        $this->notificationService->planSwitched(
            $subscription, 'downgrade_scheduled', $subscription->plan->name ?? 'their plan', $target->name,
            $this->adminUsersRepository->allAdministrators(),
        );
    }

    public function cancelScheduled(Subscription $subscription): void
    {
        $subscription->loadMissing(['plan', 'scheduledPlan', 'business']);
        $target = $subscription->scheduledPlan?->name ?? 'another plan';

        $subscription->update(['scheduled_plan_id' => null, 'scheduled_billing_cycle' => null, 'scheduled_at' => null]);

        $this->notificationService->planSwitched(
            $subscription, 'downgrade_withdrawn', $subscription->plan->name ?? 'their plan', $target,
            $this->adminUsersRepository->allAdministrators(),
        );
    }

    // A paid upgrade (SubscriptionService::activateSubscriptionFromPayment):
    // the new plan applies now; the term and cycle are unchanged. Any
    // scheduled downgrade or admin plan-edit prompt was about the old plan.
    public function applyUpgrade(Subscription $subscription, int $planId): void
    {
        $from = $subscription->plan?->name ?? 'their plan';

        $subscription->update([
            'subscription_plan_id' => $planId,
            'scheduled_plan_id' => null,
            'scheduled_billing_cycle' => null,
            'scheduled_at' => null,
            'pending_plan_id' => null,
            'plan_change_status' => null,
            'plan_change_responded_at' => null,
        ]);
        $subscription->load(['plan', 'business.owner']);

        $this->notificationService->planSwitched(
            $subscription, 'upgraded', $from, $subscription->plan->name ?? 'a new plan',
            $this->adminUsersRepository->allAdministrators(),
        );
    }

    // The owner's scheduled change, for GET /business/subscription (null
    // when there isn't one). Still shown once the term has lapsed, so the
    // plans page can point them at the plan they chose to renew on; renewing
    // creates a new subscription row, which ends it.
    public function presentScheduled(Subscription $subscription): ?array
    {
        if (! $subscription->scheduled_plan_id || $subscription->status !== 'Active') {
            return null;
        }

        $subscription->loadMissing(['scheduledPlan', 'business']);
        if (! $subscription->scheduledPlan) {
            return null;
        }

        return [
            'plan' => new SubscriptionPlanResource($subscription->scheduledPlan),
            'billing_cycle' => $subscription->scheduled_billing_cycle,
            'price' => self::price($subscription->scheduledPlan, $subscription->scheduled_billing_cycle ?? 'Monthly'),
            'effective_at' => $subscription->expires_at,
            'scheduled_at' => $subscription->scheduled_at,
            'over_limits' => $this->overLimits($subscription->business, $subscription->scheduledPlan),
        ];
    }

    // The policy as it applies to this subscription — when its grace period
    // ends and when the plan is due — for the owner's pages.
    public function presentPolicy(Subscription $subscription): array
    {
        $settings = SystemSetting::current();

        return [
            'plan_changes_enabled' => $settings->plan_changes_enabled,
            'grace_days' => $settings->planChangeGraceDays(),
            'change_until' => $this->graceEndsAt($subscription, $settings),
            'due_at' => $subscription->expires_at,
            'paid_this_term' => $this->paidThisTerm($subscription),
        ];
    }

    // The moment this term's grace period ends: its start plus the admin's
    // number of days. A row with no start date falls back to when it was
    // created.
    public function graceEndsAt(Subscription $subscription, ?SystemSetting $settings = null): ?Carbon
    {
        $start = $subscription->starts_at ?? $subscription->created_at;

        return $start?->copy()->addDays(($settings ?? SystemSetting::current())->planChangeGraceDays());
    }

    public static function price(SubscriptionPlan $plan, string $cycle): ?float
    {
        $value = $cycle === 'Yearly' ? $plan->yearly_price : $plan->monthly_price;

        return $value === null ? null : (float) $value;
    }

    public static function isLive(Subscription $subscription): bool
    {
        return $subscription->status === 'Active'
            && ($subscription->expires_at === null || $subscription->expires_at->isFuture());
    }

    // ── internals ────────────────────────────────────────────────────────

    private function blockedReason(Subscription $subscription, SubscriptionPlan $target, string $cycle, bool $isUpgrade, SystemSetting $settings): ?string
    {
        if (! self::isLive($subscription)) {
            return 'Your plan has ended — choose a plan to renew instead.';
        }
        if (! $settings->plan_changes_enabled) {
            return "Plan changes aren't available right now. Please contact Servora support.";
        }
        if (! $target->is_active) {
            return 'That plan is no longer offered.';
        }
        if (self::price($target, $cycle) === null) {
            return "The {$target->name} plan isn't offered with {$cycle} billing.";
        }
        if ($target->id === $subscription->subscription_plan_id && $cycle === ($subscription->billing_cycle ?? 'Monthly')) {
            return "You're already on this plan.";
        }
        if ($target->id === $subscription->scheduled_plan_id && $cycle === $subscription->scheduled_billing_cycle) {
            return "You've already scheduled this change.";
        }

        $expires = $subscription->expires_at;
        if (! $isUpgrade && ! $expires) {
            return "Your plan has no due date, so there's no next billing to change.";
        }

        // One grace period for upgrades and downgrades alike.
        $until = $this->graceEndsAt($subscription, $settings);
        if ($until && now()->gte($until)) {
            $window = "Plan changes were open for the first {$this->days($settings->planChangeGraceDays())} after you subscribed (until {$until->format('F j, Y')}).";

            return $expires
                ? "{$window} You can choose a different plan when your plan is due on {$expires->format('F j, Y')}."
                : $window;
        }

        return null;
    }

    private function label(Subscription $subscription, SubscriptionPlan $target, string $cycle, bool $isUpgrade): string
    {
        if ($isUpgrade) {
            return 'Upgrade';
        }
        if ($target->id === $subscription->subscription_plan_id) {
            return "Switch to {$cycle} billing";
        }

        // A bigger plan on another billing cycle also waits for renewal.
        return $cycle === ($subscription->billing_cycle ?? 'Monthly') ? 'Downgrade' : 'Change at renewal';
    }

    private function days(int $n): string
    {
        return $n === 1 ? '1 day' : "{$n} days";
    }
}
