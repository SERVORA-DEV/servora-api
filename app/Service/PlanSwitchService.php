<?php

namespace App\Service;

use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Repository\System\AdminUsersRepository;
use App\Support\PlanTerms;
use Carbon\Carbon;

// An owner moving their live subscription to another plan, under the
// admin's Subscription Policy (Settings → Subscription Policy):
//
//  - When — any time while the plan is live, up to its due date
//    (expires_at). There is no window after subscribing any more: an owner
//    who outgrows their plan on day 20 can pay to move up that day. The
//    admin's one control is the on/off switch (`plan_changes_enabled`).
//  - Upgrade — the new plan costs more than they've paid for this term.
//    They pay the difference (new price − paid so far) and switch right
//    away; the billing cycle and due date stay the same. They can upgrade
//    again later in the same term.
//  - Downgrade — anything else (a cheaper/equal plan, or another billing
//    cycle). Nothing is charged now: they keep the current plan until
//    expires_at and their next payment is for the new one. It can be asked
//    for, changed or taken back any time before the due date. When they use
//    more branches or staff logins than the new plan allows, they choose
//    what to keep; the rest goes on hold when the new term starts
//    (PlanFitService).
//
// There's no cancellation or refund — the system handles neither.
class PlanSwitchService
{
    private const UNLIMITED = 9999;

    public function __construct(
        private AdminUsersRepository $adminUsersRepository,
        private NotificationService $notificationService,
        private PlanFitService $fit,
    ) {}

    /**
     * What switching $subscription to $target on $cycle would mean.
     *
     * @return array{type: string, label: string, allowed: bool, blocked_reason: ?string,
     *   amount_due: float, paid_this_term: float, new_price: ?float, billing_cycle: string,
     *   effective_at: ?Carbon, over_limits: string[]}
     */
    public function quote(Subscription $subscription, SubscriptionPlan $target, string $cycle, array $extras = []): array
    {
        $subscription->loadMissing(['plan', 'business']);
        $settings = SystemSetting::current();
        $currentCycle = $subscription->billing_cycle ?? 'Monthly';
        $paid = $this->paidThisTerm($subscription);
        $extras = PlanTerms::extras($target, $extras);
        $effective = PlanTerms::effective($target, $extras);
        $extraPrice = PlanTerms::extraPrice($target, $cycle, $extras);
        $extraDue = max(0, $extraPrice - (float) $subscription->extras_paid);

        // Upgrades keep the current cycle; a cycle change always waits for
        // the next billing.
        $priceNow = self::price($target, $currentCycle);
        $isUpgrade = $cycle === $currentCycle && $priceNow !== null
            && $priceNow >= (float) self::price($subscription->plan, $currentCycle)
            && ($priceNow > $paid || $extraDue > 0)
            && ! $this->overLimits($subscription->business, $effective);

        $quote = [
            'type' => $isUpgrade ? 'upgrade' : 'downgrade',
            'label' => $this->label($subscription, $target, $cycle, $isUpgrade),
            'allowed' => true,
            'blocked_reason' => null,
            'amount_due' => $isUpgrade ? round(max(0, $priceNow - $paid) + $extraDue, 2) : 0.0,
            'paid_this_term' => $paid,
            'extras_paid_this_term' => (float) $subscription->extras_paid,
            'extra_branches' => $extras['extra_branches'], 'extra_accounts' => $extras['extra_accounts'],
            'extras_price' => $extraPrice,
            'new_price' => self::price($target, $cycle) === null ? null : self::price($target, $cycle) + $extraPrice,
            'billing_cycle' => $cycle,
            'effective_at' => $isUpgrade ? now() : $subscription->expires_at,
            'over_limits' => $isUpgrade ? [] : $this->overLimits($subscription->business, $effective),
            // A smaller plan: what the owner has to choose between, and what
            // the plan itself pauses. Null for an upgrade.
            'fit' => $isUpgrade || ! $subscription->business ? null : $this->fit->fit($subscription->business, $effective, $subscription->expires_at),
        ];

        $reason = $target->isTrial()
            ? 'The free trial isn\'t a plan you can switch to.'
            : $this->blockedReason($subscription, $target, $cycle, $isUpgrade, $settings, $extras);
        if ($reason) {
            $quote['allowed'] = false;
            $quote['blocked_reason'] = $reason;
        }

        return $quote;
    }

    // Where the business is over $plan's branch/account limits, as lines to
    // show the owner (empty when it fits). Branches and logins already on
    // hold don't count. The owner settles it by choosing what to keep
    // (PlanFitService); a payment on that plan is refused until they have.
    public function overLimits(?SpaBusiness $business, SubscriptionPlan $plan): array
    {
        if (! $business) {
            return [];
        }

        $lines = [];
        $branches = $this->fit->branches($business)->count();
        if ($plan->max_branches < self::UNLIMITED && $branches > $plan->max_branches) {
            $lines[] = "You have {$branches} branches; the {$plan->name} plan allows {$plan->max_branches}.";
        }

        $accounts = $this->fit->accounts($business)->count();
        if ($plan->max_user_accounts < self::UNLIMITED && $accounts > $plan->max_user_accounts) {
            $lines[] = "You have {$accounts} staff accounts; the {$plan->name} plan allows {$plan->max_user_accounts}.";
        }

        return $lines;
    }

    // Paid billings on this subscription — the first payment plus any
    // upgrades already bought this term.
    public function paidThisTerm(Subscription $subscription): float
    {
        if ($subscription->base_paid !== null) {
            return (float) $subscription->base_paid;
        }

        return round((float) $subscription->billings()->where('status', 'Paid')->sum('amount'), 2);
    }

    // $keep: what the owner chose to keep when they use more than $target
    // allows (already checked by the caller), else null.
    public function scheduleDowngrade(Subscription $subscription, SubscriptionPlan $target, string $cycle, ?array $keep = null, array $extras = []): void
    {
        $subscription->loadMissing(['plan', 'business']);

        $subscription->update([
            'scheduled_extras' => $extras,
            'scheduled_plan_id' => $target->id,
            'scheduled_billing_cycle' => $cycle,
            'scheduled_at' => now(),
            ...($this->fit->ready() ? ['scheduled_keep' => $keep] : []),
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

        $subscription->update([
            'scheduled_extras' => null,
            'scheduled_plan_id' => null, 'scheduled_billing_cycle' => null, 'scheduled_at' => null,
            ...($this->fit->ready() ? ['scheduled_keep' => null] : []),
        ]);

        $this->notificationService->planSwitched(
            $subscription, 'downgrade_withdrawn', $subscription->plan->name ?? 'their plan', $target,
            $this->adminUsersRepository->allAdministrators(),
        );
    }

    // A paid upgrade (SubscriptionService::activateSubscriptionFromPayment):
    // the new plan applies now; the term and cycle are unchanged. Any
    // scheduled downgrade or admin plan-edit prompt was about the old plan.
    public function applyUpgrade(Subscription $subscription, int $planId, array $extras = []): void
    {
        $from = $subscription->plan?->name ?? 'their plan';

        $plan = SubscriptionPlan::findOrFail($planId);
        $cycle = $subscription->billing_cycle ?? 'Monthly';
        $subscription->update([
            ...$extras,
            'base_paid' => max($this->paidThisTerm($subscription), (float) self::price($plan, $cycle)),
            'extras_paid' => max((float) $subscription->extras_paid, PlanTerms::extraPrice($plan, $cycle, $extras)),
            'scheduled_extras' => null,
            'subscription_plan_id' => $planId,
            'scheduled_plan_id' => null,
            'scheduled_billing_cycle' => null,
            'scheduled_at' => null,
            ...($this->fit->ready() ? ['scheduled_keep' => null] : []),
            'pending_plan_id' => null,
            'plan_change_status' => null,
            'plan_change_responded_at' => null,
        ]);
        $subscription->load(['plan', 'business.owner']);

        // A bigger plan may have room for what an earlier one put on hold.
        if ($subscription->business && $subscription->plan) {
            $this->fit->release($subscription->business, PlanTerms::effective($subscription->plan, $extras));
        }

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

        $effective = PlanTerms::effective($subscription->scheduledPlan, $subscription->scheduled_extras ?? []);

        return [
            'plan' => new SubscriptionPlanResource($subscription->scheduledPlan),
            'billing_cycle' => $subscription->scheduled_billing_cycle,
            'extra_branches' => $subscription->scheduled_extras['extra_branches'] ?? 0,
            'extra_accounts' => $subscription->scheduled_extras['extra_accounts'] ?? 0,
            'price' => self::price($subscription->scheduledPlan, $subscription->scheduled_billing_cycle ?? 'Monthly') + PlanTerms::extraPrice($subscription->scheduledPlan, $subscription->scheduled_billing_cycle ?? 'Monthly', $subscription->scheduled_extras ?? []),
            'effective_at' => $subscription->expires_at,
            'scheduled_at' => $subscription->scheduled_at,
            'over_limits' => $this->overLimits($subscription->business, $effective),
            // What the owner chose to keep, and what is still wrong with that
            // choice today (a booking made since, a branch added since).
            'keep' => $this->fit->ready() ? $this->fit->presentKeep($subscription->business, $subscription->scheduled_keep) : null,
            'keep_problems' => $subscription->business
                ? $this->fit->problems($subscription->business, $effective, $subscription->scheduled_keep, $subscription->expires_at)
                : [],
        ];
    }

    // The policy as it applies to this subscription, for the owner's pages:
    // whether plan changes are on, and when the plan is due (changes are
    // open until then).
    public function presentPolicy(Subscription $subscription): array
    {
        return [
            'plan_changes_enabled' => SystemSetting::current()->plan_changes_enabled,
            'due_at' => $subscription->expires_at,
            'paid_this_term' => $this->paidThisTerm($subscription),
        ];
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

    private function blockedReason(Subscription $subscription, SubscriptionPlan $target, string $cycle, bool $isUpgrade, SystemSetting $settings, array $extras = []): ?string
    {
        if (! self::isLive($subscription)) {
            return 'Your plan has ended — choose a plan to renew instead.';
        }
        if (! $settings->plan_changes_enabled) {
            return "Plan changes aren't available right now. Please contact Servora support.";
        }
        if (! $target->is_active && $target->id !== $subscription->subscription_plan_id) {
            return 'That plan is no longer offered.';
        }
        if (self::price($target, $cycle) === null) {
            return "The {$target->name} plan isn't offered with {$cycle} billing.";
        }
        if ($target->id === $subscription->subscription_plan_id && $cycle === ($subscription->billing_cycle ?? 'Monthly') && ($extras['extra_branches'] ?? 0) === (int) $subscription->extra_branches && ($extras['extra_accounts'] ?? 0) === (int) $subscription->extra_accounts) {
            return "You're already on this plan.";
        }
        if ($target->id === $subscription->scheduled_plan_id && $cycle === $subscription->scheduled_billing_cycle && $extras === ($subscription->scheduled_extras ?? [])) {
            return "You've already scheduled this change.";
        }

        // A downgrade waits for the next billing, so it needs one. Beyond
        // that there is no window: any time before the due date is fine.
        if (! $isUpgrade && ! $subscription->expires_at) {
            return "Your plan has no due date, so there's no next billing to change.";
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
}
