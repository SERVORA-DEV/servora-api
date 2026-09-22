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
use Illuminate\Support\Facades\Cache;

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
        // The database is remote, so every query here is a network round
        // trip. The overview is read-only and tolerates being a few seconds
        // stale, so repeat loads (page revisits, several admins) within the
        // window are served from cache.
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn () => $this->buildOverview());
    }

    public const CACHE_KEY = 'system:dashboard:overview';

    private const CACHE_TTL_SECONDS = 30;

    private function buildOverview(): array
    {
        $now = Carbon::now();
        $thisMonthEnd = $now->copy()->endOfMonth();
        $lastMonthEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $months = $this->trailingMonths();

        $businessGrowth = $this->businessGrowthSeries($months);
        $subscriptionGrowth = $this->subscriptionGrowthSeries($months);
        $revenueTrend = $this->revenueSeries($months);

        // Total plus the two month-end snapshots used for the trend, in one query.
        $branchCounts = SpaBranch::whereIn('verification_status', ['Verified', 'Suspended'])
            ->selectRaw(
                'count(*) as total,
                 sum(case when verified_at <= ? then 1 else 0 end) as this_month,
                 sum(case when verified_at <= ? then 1 else 0 end) as last_month',
                [$thisMonthEnd, $lastMonthEnd]
            )
            ->first();

        return [
            'kpis' => [
                'total_businesses' => [
                    'value' => SpaBusiness::count(),
                    'trend_pct' => $this->deltaPct($businessGrowth[5], $businessGrowth[4]),
                ],
                'total_branches' => [
                    'value' => (int) $branchCounts->total,
                    'trend_pct' => $this->deltaPct(
                        (int) $branchCounts->this_month,
                        (int) $branchCounts->last_month
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
     * The trailing 6 months (oldest first, current month last) as
     * ['key' => 'YYYY-MM', 'start' => Carbon, 'end' => Carbon].
     */
    private function trailingMonths(): array
    {
        $months = [];

        for ($i = 5; $i >= 0; $i--) {
            $start = Carbon::now()->subMonthsNoOverflow($i)->startOfMonth();

            $months[] = [
                'key' => $start->format('Y-m'),
                'start' => $start,
                'end' => $start->copy()->endOfMonth(),
            ];
        }

        return $months;
    }

    // New businesses per month: one grouped query over the whole window
    // instead of one count per month. Timestamps are naive app-timezone
    // values, so to_char buckets them the same way the Carbon bounds do.
    private function businessGrowthSeries(array $months): array
    {
        $counts = SpaBusiness::whereBetween('created_at', [$months[0]['start'], $months[5]['end']])
            ->selectRaw("to_char(created_at, 'YYYY-MM') as month, count(*) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        return array_map(fn ($m) => (int) ($counts[$m['key']] ?? 0), $months);
    }

    // Paid revenue per month, one grouped query.
    private function revenueSeries(array $months): array
    {
        $sums = Payment::where('payment_status', 'Paid')
            ->whereBetween('paid_at', [$months[0]['start'], $months[5]['end']])
            ->selectRaw("to_char(paid_at, 'YYYY-MM') as month, sum(amount) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        return array_map(fn ($m) => (float) ($sums[$m['key']] ?? 0), $months);
    }

    // Subscriptions active at any point in each month. A subscription can
    // span several months, so this can't be a GROUP BY: load the (few)
    // subscriptions overlapping the window once, then apply the same
    // predicate as activeSubscriptionsDuring() per month in PHP.
    private function subscriptionGrowthSeries(array $months): array
    {
        $subscriptions = $this->activeSubscriptionsDuring($months[0]['start'], $months[5]['end'])
            ->get(['starts_at', 'expires_at', 'cancelled_at']);

        return array_map(function ($m) use ($subscriptions) {
            return $subscriptions->filter(function ($sub) use ($m) {
                return $sub->starts_at !== null
                    && $sub->starts_at->lte($m['end'])
                    && ($sub->expires_at === null || $sub->expires_at->gte($m['start']))
                    && ($sub->cancelled_at === null || $sub->cancelled_at->gte($m['start']));
            })->count();
        }, $months);
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
