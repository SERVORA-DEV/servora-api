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
