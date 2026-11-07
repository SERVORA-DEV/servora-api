<?php

namespace App\Service;

use App\Support\AppCache;
use App\Support\PlanTerms;

class PlanAccessService
{
    // Called while holding the business row lock in the appointment transaction. App bookings
    // have no monthly limit: a plan with the client app listing takes as many as come.
    public function assertCanBook(int $businessId): void
    {
        abort_unless(AppCache::planAllows($businessId, 'mobile_app_access'), 403, 'This spa is not accepting app bookings on its current plan.');
    }

    public function present(int $businessId): array
    {
        $sub = AppCache::activeSubscription($businessId);
        $plan = $sub?->plan;
        $effective = $plan ? PlanTerms::effective($plan, $sub->only(['extra_branches', 'extra_accounts'])) : null;

        return [
            'max_branches' => $effective?->max_branches ?? 0,
            'max_user_accounts' => $effective?->max_user_accounts ?? 0,
            'extra_branches' => $sub?->extra_branches ?? 0,
            'extra_accounts' => $sub?->extra_accounts ?? 0,
            'export_formats' => $plan ? PlanTerms::exportFormats($plan) : [],
            'export_days' => $plan?->export_days,
            'report_access' => (bool) $plan?->report_access,
            'extras_renewal_amount' => $plan ? PlanTerms::extraPrice($plan, $sub->billing_cycle ?? 'Monthly', $sub->only(['extra_branches', 'extra_accounts'])) : 0,
        ];
    }
}
