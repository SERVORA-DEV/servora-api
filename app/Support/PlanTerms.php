<?php

namespace App\Support;

use App\Models\SubscriptionPlan;

/** Purchased extras belong to a term; catalog limits remain immutable. */
final class PlanTerms
{
    public static function extras(SubscriptionPlan $plan, array $input): array
    {
        $branches = max(0, (int) ($input['extra_branches'] ?? 0));
        $accounts = max(0, (int) ($input['extra_accounts'] ?? 0));
        if ($branches > 1000 || $accounts > 1000) {
            throw new \InvalidArgumentException('Choose no more than 1,000 extras of each kind.');
        }
        if (($branches && $plan->extra_branch_price === null) || ($accounts && $plan->extra_account_price === null) || ($plan->isTrial() && ($branches || $accounts))) {
            throw new \InvalidArgumentException('This plan does not offer the selected extra capacity. Choose another plan.');
        }

        return ['extra_branches' => $branches, 'extra_accounts' => $accounts];
    }

    public static function extraPrice(SubscriptionPlan $plan, string $cycle, array $extras): float
    {
        return round(((float) $plan->extra_branch_price * ($extras['extra_branches'] ?? 0)
            + (float) $plan->extra_account_price * ($extras['extra_accounts'] ?? 0)) * ($cycle === 'Yearly' ? 12 : 1), 2);
    }

    // Whether $plan sells extra slots of $kind ('branch' or 'account').
    public static function offers(SubscriptionPlan $plan, string $kind): bool
    {
        return ! $plan->isTrial() && $plan->{"extra_{$kind}_price"} !== null && (float) $plan->{"extra_{$kind}_price"} > 0;
    }

    // One slot of $kind for a whole billing cycle: the plan's monthly price
    // per slot, × 12 for yearly billing (plans have no separate yearly
    // per-slot price). Null when the plan doesn't sell that slot.
    public static function slotPrice(SubscriptionPlan $plan, string $kind, string $cycle): ?float
    {
        if (! self::offers($plan, $kind)) {
            return null;
        }

        return round((float) $plan->{"extra_{$kind}_price"} * ($cycle === 'Yearly' ? 12 : 1), 2);
    }

    // The extras a term on $plan keeps: slots $plan doesn't sell are dropped
    // (they end with the old plan) instead of refusing the renewal.
    public static function carryOver(SubscriptionPlan $plan, array $extras): array
    {
        return [
            'extra_branches' => self::offers($plan, 'branch') ? max(0, (int) ($extras['extra_branches'] ?? 0)) : 0,
            'extra_accounts' => self::offers($plan, 'account') ? max(0, (int) ($extras['extra_accounts'] ?? 0)) : 0,
        ];
    }

    // A full term on $plan with $extras: the plan price plus every extra
    // slot at its full price (₱3,000 + 1 account × ₱100 = ₱3,100). Null when
    // the plan isn't offered on that cycle.
    public static function renewalAmount(SubscriptionPlan $plan, string $cycle, array $extras): ?float
    {
        $base = $cycle === 'Yearly' ? $plan->yearly_price : $plan->monthly_price;

        return $base === null ? null : round((float) $base + self::extraPrice($plan, $cycle, $extras), 2);
    }

    public static function effective(SubscriptionPlan $plan, array $extras): SubscriptionPlan
    {
        $effective = clone $plan;
        $effective->max_branches = (int) $plan->max_branches + (int) ($extras['extra_branches'] ?? 0);
        $effective->max_user_accounts = (int) $plan->max_user_accounts + (int) ($extras['extra_accounts'] ?? 0);

        return $effective;
    }

    public static function exportFormats(SubscriptionPlan $plan): array
    {
        if (! $plan->report_access || ! $plan->report_export) {
            return [];
        }

        return match ($plan->export_mode) {
            'csv' => ['csv'],
            'full' => ['pdf', 'excel', 'csv'],
            default => $plan->export_mode === null ? ['pdf', 'excel'] : [],
        };
    }
}
