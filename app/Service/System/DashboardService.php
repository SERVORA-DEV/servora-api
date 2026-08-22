<?php

namespace App\Service\System;

use App\Http\Resources\TransactionResource;
use App\Models\Payment;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Repository\AuditLogRepository;
use App\Repository\BillingRepository;
use Illuminate\Support\Carbon;

class DashboardService
{
    private BillingRepository $billingRepository;

    private AuditLogRepository $auditLogRepository;

    public function __construct(BillingRepository $billingRepository, AuditLogRepository $auditLogRepository)
    {
        $this->billingRepository = $billingRepository;
        $this->auditLogRepository = $auditLogRepository;
    }

    /**
     * Platform-wide overview for the system administrator dashboard.
     *
     * There is no Customer model and no health/observability infrastructure
     * anywhere in the codebase, so 'active_customers' is left null (not 0)
     * and no platform_health/system_status/active_services keys are emitted
     * at all — the frontend keeps those tiles in their existing "no data
     * yet" empty state rather than showing a fabricated number.
     */
    public function getOverview(): array
    {
        $now = Carbon::now();
        $thisMonthStart = $now->copy()->startOfMonth();
        $thisMonthEnd = $now->copy()->endOfMonth();
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $businessGrowth = $this->monthlySeries(
            fn (Carbon $start, Carbon $end) => SpaBusiness::whereBetween('created_at', [$start, $end])->count()
        );

        $subscriptionGrowth = $this->monthlySeries(
            fn (Carbon $start, Carbon $end) => $this->activeSubscriptionsDuring($start, $end)->count()
        );

        $revenueTrend = $this->monthlySeries(
            fn (Carbon $start, Carbon $end) => (float) Payment::where('payment_status', 'Paid')
                ->whereBetween('paid_at', [$start, $end])
                ->sum('amount')
        );

        $totalBranchesQuery = fn () => SpaBranch::whereIn('verification_status', ['Verified', 'Suspended']);

        return [
            'kpis' => [
                'total_businesses' => [
                    'value' => SpaBusiness::count(),
                    'trend_pct' => $this->deltaPct($businessGrowth[5], $businessGrowth[4]),
                ],
                'total_branches' => [
                    'value' => (clone $totalBranchesQuery())->count(),
                    'trend_pct' => $this->deltaPct(
                        (clone $totalBranchesQuery())->where('verified_at', '<=', $thisMonthEnd)->count(),
                        (clone $totalBranchesQuery())->where('verified_at', '<=', $lastMonthEnd)->count()
                    ),
                ],
                'active_customers' => null,
                'monthly_revenue' => [
                    'value' => $revenueTrend[5],
                    'trend_pct' => $this->deltaPct($revenueTrend[5], $revenueTrend[4]),
                ],
                'active_subscriptions' => [
                    'value' => $this->currentlyActiveSubscriptions()->count(),
                    'trend_pct' => $this->deltaPct($subscriptionGrowth[5], $subscriptionGrowth[4]),
                ],
            ],
            'analytics' => [
                'business_growth' => $businessGrowth,
                'subscription_growth' => $subscriptionGrowth,
                'revenue_trend' => $revenueTrend,
            ],
            'pending_registrations' => SpaBranch::where('verification_status', 'Pending')->count(),
            'recent_transactions' => TransactionResource::collection(
                $this->billingRepository->recentSubscriptionTransactions(8)
            )->resolve(),
            'audit_logs' => $this->auditLogRepository->recent(8)->map(fn ($log) => [
                'id' => $log->id,
                'actor' => $log->user
                    ? trim("{$log->user->first_name} {$log->user->last_name}")
                    : 'System',
                'action' => $log->action,
                'table_name' => $log->table_name,
                'record_id' => $log->record_id,
                'created_at' => optional($log->created_at)->toIso8601String(),
            ])->values()->all(),
            'new_businesses' => SpaBusiness::with(['owner', 'activeSubscription.plan'])
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (SpaBusiness $business) => [
                    'uuid' => $business->uuid,
                    'business_name' => $business->business_name,
                    'owner_first_name' => $business->owner?->first_name,
                    'owner_last_name' => $business->owner?->last_name,
                    'plan_category' => $business->activeSubscription?->plan?->category,
                    'created_at' => optional($business->created_at)->toIso8601String(),
                ])->values()->all(),
        ];
    }

    /**
     * Runs $metric once per month for the trailing 6 months (oldest first,
     * current month last), matching Business\DashboardService's day-loop
     * pattern but at month grain.
     */
    private function monthlySeries(callable $metric): array
    {
        $series = [];

        for ($i = 5; $i >= 0; $i--) {
            $monthStart = Carbon::now()->subMonthsNoOverflow($i)->startOfMonth();
            $monthEnd = Carbon::now()->subMonthsNoOverflow($i)->endOfMonth();

            $series[] = $metric($monthStart, $monthEnd);
        }

        return $series;
    }

    // Subscriptions active at any point during [$start, $end], reconstructed
    // from timestamps rather than the mutable `status` column — nothing
    // flips a row to 'Expired' automatically yet (see
    // SubscriptionRepository::findActiveForBusiness), so a status-only query
    // would misrepresent past months once a subscription later lapses.
    private function activeSubscriptionsDuring(Carbon $start, Carbon $end)
    {
        return Subscription::where('starts_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $start))
            ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>=', $start));
    }

    // Currently-active subscriptions for the KPI value — status='Active'
    // guarded by expires_at, for the same staleness reason as above.
    private function currentlyActiveSubscriptions()
    {
        return Subscription::where('status', 'Active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()));
    }

    private function deltaPct(int|float $current, int|float $previous): float
    {
        if ($previous > 0) {
            return round((($current - $previous) / $previous) * 100, 1);
        }

        return $current > 0 ? 100.0 : 0.0;
    }
}
