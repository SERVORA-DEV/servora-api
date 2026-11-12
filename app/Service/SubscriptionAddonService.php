<?php

namespace App\Service;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\PlanTerms;
use App\Support\SlotProration;
use Carbon\CarbonInterface;

// Extra branch / account slots bought in the middle of a paid term
// (checkout purpose "add_slots"):
//
//  - Only a plan with an extra branch / account price sells that slot.
//  - The owner pays for the days left until the next billing only
//    (SlotProration); the slots apply as soon as the payment succeeds
//    (CheckoutService::complete → apply) and the branch / account limits
//    count them from then on (PlanTerms::effective).
//  - From the next billing on they renew at the full per-slot price with the
//    plan (PlanTerms::renewalAmount, SubscriptionRenewalService).
//  - They belong to the term: a term that ends (cancelled, not renewed)
//    takes them with it. A plan change keeps them only where the new plan
//    sells that slot (PlanTerms::carryOver); the owner is told before.
//
// Each purchase is recorded as its checkout session (meta.add: what was
// added, the days charged, the formula) and an invoice — no table of its own.
class SubscriptionAddonService
{
    public const KINDS = ['branch' => 'extra_branches', 'account' => 'extra_accounts'];

    private const MAX_EACH = 1000;

    /**
     * What the owner can add to $subscription, for the subscription page and
     * the limit prompts. Null kinds aren't sold on the plan.
     */
    public function offer(?Subscription $subscription, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $plan = $subscription?->plan;
        $reason = $this->unavailableReason($subscription, $now);
        $cycle = $subscription?->billing_cycle ?? 'Monthly';

        $kinds = [];
        foreach (self::KINDS as $kind => $field) {
            $kinds[$kind] = $plan && PlanTerms::offers($plan, $kind) ? [
                'monthly_price' => (float) $plan->{"extra_{$kind}_price"},
                'cycle_price' => PlanTerms::slotPrice($plan, $kind, $cycle),
                'owned' => (int) $subscription->{$field},
            ] : null;
        }
        [$left, $period] = $reason === null ? SlotProration::days($this->termStart($subscription), $subscription->expires_at, $now) : [null, null];

        return [
            'available' => $reason === null && ($kinds['branch'] || $kinds['account']),
            'unavailable_reason' => $reason,
            'billing_cycle' => $cycle,
            'next_billing_at' => $subscription?->expires_at,
            'days_left' => $left,
            'period_days' => $period,
            'branch' => $kinds['branch'],
            'account' => $kinds['account'],
        ];
    }

    /**
     * The price of adding $input (add_branches / add_accounts) now.
     *
     * @return array{allowed: bool, blocked_reason: ?string, amount: float, lines: array, notes: string[]}
     */
    public function quote(?Subscription $subscription, array $input, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $add = self::wanted($input);
        $plan = $subscription?->plan;
        $cycle = $subscription?->billing_cycle ?? 'Monthly';

        $blocked = $this->unavailableReason($subscription, $now) ?? $this->quantityProblem($subscription, $add);
        [$left, $period] = $subscription?->expires_at
            ? SlotProration::days($this->termStart($subscription), $subscription->expires_at, $now)
            : [0, 0];

        $lines = [];
        $amount = 0;
        if ($plan && $subscription?->expires_at) {
            foreach (self::KINDS as $kind => $field) {
                $qty = $add[$field];
                $price = PlanTerms::slotPrice($plan, $kind, $cycle);
                if ($qty <= 0 || $price === null) {
                    continue;
                }
                $lineAmount = SlotProration::amount($price, $qty, $left, $period);
                $amount += $lineAmount;
                $lines[] = [
                    'kind' => $kind,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'amount' => (float) $lineAmount,
                    'label' => self::formula($kind, $qty, $price, $cycle, $left, $subscription->expires_at, $now),
                ];
            }
        }

        $after = $subscription ? [
            'extra_branches' => (int) $subscription->extra_branches + $add['extra_branches'],
            'extra_accounts' => (int) $subscription->extra_accounts + $add['extra_accounts'],
        ] : ['extra_branches' => 0, 'extra_accounts' => 0];

        return [
            'allowed' => $blocked === null,
            'blocked_reason' => $blocked,
            'add_branches' => $add['extra_branches'],
            'add_accounts' => $add['extra_accounts'],
            'amount' => (float) $amount,
            'lines' => $lines,
            'days_left' => $left,
            'period_days' => $period,
            'billing_cycle' => $cycle,
            'next_billing_at' => $subscription?->expires_at,
            'extras_after' => $after,
            'limits_after' => $plan ? [
                'max_branches' => (int) $plan->max_branches + $after['extra_branches'],
                'max_user_accounts' => (int) $plan->max_user_accounts + $after['extra_accounts'],
            ] : null,
            // The next billing with these slots in it (on the plan the next
            // term is on, keeping only the slots that plan sells).
            'next_amount' => $subscription ? $this->nextAmount($subscription, $after, $add) : null,
            'notes' => $subscription && $blocked === null ? $this->notes($subscription, $add) : [],
        ];
    }

    /**
     * The next billing as it stands: the plan the next term is on, the extra
     * slots it keeps and the full amount (plan + every slot at full price).
     */
    public function nextBilling(?Subscription $subscription): ?array
    {
        if (! $subscription?->plan || $subscription->is_trial || ! $subscription->expires_at) {
            return null;
        }
        $subscription->loadMissing(['scheduledPlan', 'pendingPlan']);
        [$plan, $cycle, $scheduled] = $this->nextPlan($subscription);
        $extras = PlanTerms::carryOver($plan, $scheduled
            ? ($subscription->scheduled_extras ?? [])
            : $subscription->only(['extra_branches', 'extra_accounts']));
        $base = PlanSwitchService::price($plan, $cycle);

        return [
            'at' => $subscription->expires_at,
            'plan_name' => $plan->name,
            'billing_cycle' => $cycle,
            'plan_amount' => $base,
            'extra_branches' => $extras['extra_branches'],
            'extra_accounts' => $extras['extra_accounts'],
            'extras_amount' => PlanTerms::extraPrice($plan, $cycle, $extras),
            'amount' => PlanTerms::renewalAmount($plan, $cycle, $extras),
        ];
    }

    /**
     * A paid add-on: the slots apply to the term now. Called inside
     * CheckoutService::complete's transaction. A plan change scheduled for
     * the next billing keeps them where that plan sells the slot.
     */
    public function apply(Subscription $subscription, array $add): Subscription
    {
        $subscription = Subscription::with(['plan', 'scheduledPlan'])->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
        $add = self::wanted($add);
        $extras = [
            'extra_branches' => (int) $subscription->extra_branches + $add['extra_branches'],
            'extra_accounts' => (int) $subscription->extra_accounts + $add['extra_accounts'],
        ];
        $cycle = $subscription->billing_cycle ?? 'Monthly';

        $update = [
            ...$extras,
            // This term's extras count as paid for (prorated), so an upgrade
            // later in the term doesn't charge them again.
            'extras_paid' => $subscription->plan
                ? max((float) $subscription->extras_paid, PlanTerms::extraPrice($subscription->plan, $cycle, $extras))
                : (float) $subscription->extras_paid,
        ];
        if ($subscription->scheduled_plan_id && $subscription->scheduledPlan) {
            $scheduled = $subscription->scheduled_extras ?? [];
            $update['scheduled_extras'] = PlanTerms::carryOver($subscription->scheduledPlan, [
                'extra_branches' => (int) ($scheduled['extra_branches'] ?? 0) + $add['extra_branches'],
                'extra_accounts' => (int) ($scheduled['extra_accounts'] ?? 0) + $add['extra_accounts'],
            ]);
        }

        $subscription->update($update);

        return $subscription;
    }

    /** @return array{extra_branches: int, extra_accounts: int} */
    public static function wanted(array $input): array
    {
        return [
            'extra_branches' => max(0, (int) ($input['add_branches'] ?? $input['extra_branches'] ?? 0)),
            'extra_accounts' => max(0, (int) ($input['add_accounts'] ?? $input['extra_accounts'] ?? 0)),
        ];
    }

    // "2 accounts × ₱100/month for the 17 days left until Feb 1"
    public static function formula(string $kind, int $qty, float $cyclePrice, string $cycle, int $daysLeft, CarbonInterface $due, CarbonInterface $now): string
    {
        $tz = (string) config('app.timezone', 'Asia/Manila');
        $dueLocal = $due->copy()->setTimezone($tz);
        $date = $dueLocal->year === $now->copy()->setTimezone($tz)->year ? $dueLocal->format('M j') : $dueLocal->format('M j, Y');
        $what = $qty.' '.($qty === 1 ? $kind : ($kind === 'branch' ? 'branches' : 'accounts'));
        $days = $daysLeft === 1 ? 'the 1 day left' : "the {$daysLeft} days left";

        return "{$what} × ".self::peso($cyclePrice).'/'.($cycle === 'Yearly' ? 'year' : 'month')." for {$days} until {$date}";
    }

    public static function peso(float $amount): string
    {
        return '₱'.number_format($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2);
    }

    // ── internals ────────────────────────────────────────────────────────

    private function unavailableReason(?Subscription $subscription, CarbonInterface $now): ?string
    {
        $plan = $subscription?->plan;

        return match (true) {
            ! $subscription || ! $plan => 'Choose a plan first. Extra slots are added to a paid plan.',
            (bool) $subscription->is_trial || $plan->isTrial() => 'Extra slots are for paid plans. Choose a plan to add them.',
            $subscription->status !== 'Active' || ($subscription->expires_at && $subscription->expires_at->lte($now)) => 'Your plan has ended. Renew it first.',
            ! $subscription->expires_at => 'Your plan has no next billing date, so extra slots can\'t be priced.',
            ! PlanTerms::offers($plan, 'branch') && ! PlanTerms::offers($plan, 'account') => "The {$plan->name} plan doesn't offer extra branches or accounts.",
            default => null,
        };
    }

    private function quantityProblem(?Subscription $subscription, array $add): ?string
    {
        $plan = $subscription?->plan;
        if (! $plan) {
            return null;
        }
        if ($add['extra_branches'] + $add['extra_accounts'] <= 0) {
            return 'Enter how many to add.';
        }
        if ($add['extra_branches'] > 0 && ! PlanTerms::offers($plan, 'branch')) {
            return "The {$plan->name} plan doesn't offer extra branches.";
        }
        if ($add['extra_accounts'] > 0 && ! PlanTerms::offers($plan, 'account')) {
            return "The {$plan->name} plan doesn't offer extra accounts.";
        }
        if ((int) $subscription->extra_branches + $add['extra_branches'] > self::MAX_EACH || (int) $subscription->extra_accounts + $add['extra_accounts'] > self::MAX_EACH) {
            return 'You can have up to 1,000 extra slots of each kind.';
        }

        return null;
    }

    // Older rows may lack starts_at: the term is one cycle back from the due date.
    private function termStart(Subscription $subscription): CarbonInterface
    {
        return $subscription->starts_at
            ?? (($subscription->billing_cycle ?? 'Monthly') === 'Yearly' ? $subscription->expires_at->copy()->subYear() : $subscription->expires_at->copy()->subMonth());
    }

    // The plan the next term is on, as SubscriptionRenewalService picks it.
    private function nextPlan(Subscription $subscription): array
    {
        $cycle = $subscription->billing_cycle ?? 'Monthly';
        if ($subscription->scheduled_plan_id && $subscription->scheduledPlan) {
            return [$subscription->scheduledPlan, $subscription->scheduled_billing_cycle ?? $cycle, true];
        }
        if ($subscription->pending_plan_id && $subscription->pendingPlan && in_array($subscription->plan_change_status, ['pending', 'accepted'], true)) {
            return [$subscription->pendingPlan, $cycle, false];
        }

        return [$subscription->plan, $cycle, false];
    }

    private function nextAmount(Subscription $subscription, array $after, array $add): ?float
    {
        if (! $subscription->plan) {
            return null;
        }
        [$plan, $cycle, $scheduled] = $this->nextPlan($subscription);
        $extras = $scheduled
            ? [
                'extra_branches' => (int) ($subscription->scheduled_extras['extra_branches'] ?? 0) + $add['extra_branches'],
                'extra_accounts' => (int) ($subscription->scheduled_extras['extra_accounts'] ?? 0) + $add['extra_accounts'],
            ]
            : $after;

        return PlanTerms::renewalAmount($plan, $cycle, PlanTerms::carryOver($plan, $extras));
    }

    // What the owner should know before paying.
    private function notes(Subscription $subscription, array $add): array
    {
        $notes = [];
        $due = $subscription->expires_at?->copy()->setTimezone((string) config('app.timezone', 'Asia/Manila'))->format('F j, Y');
        if ($subscription->isEndingByChoice() || ! $subscription->auto_renew) {
            $notes[] = $subscription->isEndingByChoice()
                ? "Your plan ends on {$due}, and these slots end with it."
                : "Auto-renew is off: these slots last until {$due} unless you renew.";
        }
        [$plan, , $changes] = $this->nextPlan($subscription);
        if ($plan && $plan->id !== $subscription->subscription_plan_id) {
            foreach (self::KINDS as $kind => $field) {
                if ($add[$field] > 0 && ! PlanTerms::offers($plan, $kind)) {
                    $plural = $kind === 'branch' ? 'branches' : 'accounts';
                    $notes[] = ($changes ? 'Your change to the ' : 'The updated ')."{$plan->name} plan on {$due} has no extra {$plural}, so these end then.";
                }
            }
        }

        return $notes;
    }
}
