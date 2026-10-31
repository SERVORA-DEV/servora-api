<?php

namespace App\Service;

use App\Models\Appointment;
use App\Support\AppCache;
use App\Support\PlanTerms;

class PlanAccessService
{
    public function bookingUsage(int $businessId): array
    {
        $month = now('Asia/Manila')->startOfMonth();
        $plan = AppCache::activeSubscription($businessId)?->plan;
        $used = Appointment::withTrashed()->where('source', 'Mobile')
            ->whereHas('branch', fn ($q) => $q->withTrashed()->where('spa_business_id', $businessId))
            ->where('created_at', '>=', $month->copy()->timezone(config('app.timezone')))->where('created_at', '<', $month->copy()->addMonth()->timezone(config('app.timezone')))->count();

        return ['used' => $used, 'limit' => $plan?->app_booking_limit, 'resets_at' => $month->copy()->addMonth()->toIso8601String()];
    }

    // Called while holding the business row lock in the appointment transaction.
    public function assertCanBook(int $businessId): void
    {
        abort_unless(AppCache::planAllows($businessId, 'mobile_app_access'), 403, 'This spa is not accepting app bookings on its current plan.');
        $usage = $this->bookingUsage($businessId);
        abort_if($usage['limit'] !== null && $usage['used'] >= $usage['limit'], 422, 'This spa has reached its app booking allowance for this month. Existing bookings are unaffected.');
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
            'booking_usage' => $this->bookingUsage($businessId),
            'extras_renewal_amount' => $plan ? PlanTerms::extraPrice($plan, $sub->billing_cycle ?? 'Monthly', $sub->only(['extra_branches', 'extra_accounts'])) : 0,
        ];
    }
}
