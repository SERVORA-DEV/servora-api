<?php

namespace App\Service\System;

use App\Http\Resources\TransactionResource;
use App\Models\OwnerIdentityVerification;
use App\Models\Payment;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\Subscription;
use App\Models\SystemSetting;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\BillingRepository;
use App\Repository\SubscriptionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class DashboardService
{
    private BillingRepository $billingRepository;

    private AuditLogRepository $auditLogRepository;

    private SubscriptionRepository $subscriptionRepository;

    public function __construct(BillingRepository $billingRepository, AuditLogRepository $auditLogRepository, SubscriptionRepository $subscriptionRepository)
    {
        $this->billingRepository = $billingRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->subscriptionRepository = $subscriptionRepository;
    }

    // How far ahead "ending soon" looks, and how close a trial's end has to
    // be to count as needing a look.
    private const ENDING_SOON_DAYS = 14;
    private const TRIAL_ENDING_DAYS = 7;

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

        // Every subscription that is live right now — each business's latest
        // one only (a renewal adds a row and leaves the old one "Active") —
        // read once with its plan and business. The paid/trial split, the
        // plan mix, "trials ending" and "ending soon" all come from it.
        $live = $this->currentlyActiveSubscriptions()
            ->whereRaw('subscriptions.id = (select max(s2.id) from subscriptions s2 where s2.spa_business_id = subscriptions.spa_business_id and s2.deleted_at is null)')
            ->with(['plan', 'business'])
            ->get();
        $trials = $live->where('is_trial', true);

        // People registered in the client app.
        $clients = User::where('role', 'client')
            ->selectRaw('count(*) as total, sum(case when created_at >= ? then 1 else 0 end) as this_month', [$now->copy()->startOfMonth()])
            ->first();

        $pendingBranches = SpaBranch::where('verification_status', 'Pending')->count();

        return [
            // What is waiting for an administrator to do something.
            'attention' => [
                'owner_verifications' => OwnerIdentityVerification::where('status', 'Pending')->count(),
                'branch_registrations' => $pendingBranches,
                // Past its due date, not renewed, and still inside the grace
                // period: the same businesses the Subscribers list shows as
                // Overdue, which is where this row links. Past the grace
                // period they are under "Did not renew" there instead.
                'plans_overdue' => $this->subscriptionRepository->countOverdue(0)
                    - $this->subscriptionRepository->countOverdue((int) SystemSetting::current()->subscription_grace_period_days),
                'trials_ending' => $trials->filter(fn ($s) => $s->expires_at && $s->expires_at->lte($now->copy()->addDays(self::TRIAL_ENDING_DAYS)))->count(),
                // How long the oldest waiting request has been waiting, as the
                // time it was last changed to Pending (a row is made Unregistered
                // and moves to Pending when the owner submits it). Null when
                // nothing is waiting.
                'oldest_owner_verification_at' => $this->isoOrNull(OwnerIdentityVerification::where('status', 'Pending')->min('updated_at')),
                'oldest_branch_registration_at' => $this->isoOrNull(SpaBranch::where('verification_status', 'Pending')->min('updated_at')),
            ],
            'kpis' => [
                'total_businesses' => [
                    'value' => SpaBusiness::count(),
                    'trend_pct' => $this->deltaPct($businessGrowth[5], $businessGrowth[4]),
                    'new_this_month' => $businessGrowth[5],
                ],
                'total_branches' => [
                    'value' => (int) $branchCounts->total,
                    'trend_pct' => $this->deltaPct(
                        (int) $branchCounts->this_month,
                        (int) $branchCounts->last_month
                    ),
                    'new_this_month' => max(0, (int) $branchCounts->this_month - (int) $branchCounts->last_month),
                ],
                'active_customers' => null,
                'client_accounts' => [
                    'value' => (int) ($clients->total ?? 0),
                    'new_this_month' => (int) ($clients->this_month ?? 0),
                ],
                'monthly_revenue' => [
                    'value' => $revenueTrend[5],
                    'trend_pct' => $this->deltaPct($revenueTrend[5], $revenueTrend[4]),
                    'last_month' => $revenueTrend[4],
                ],
                'active_subscriptions' => [
                    'value' => $live->count(),
                    'trend_pct' => $this->deltaPct($subscriptionGrowth[5], $subscriptionGrowth[4]),
                    'paid' => $live->count() - $trials->count(),
                    'trial' => $trials->count(),
                ],
            ],
            'subscriptions' => [
                // Live subscriptions by plan, biggest first.
                'by_plan' => $live
                    ->groupBy(fn ($s) => $s->plan?->category ?? 'Other')
                    ->map(fn ($group, $category) => [
                        'category' => $category,
                        'name' => $group->first()->plan?->name ?? $category,
                        'count' => $group->count(),
                    ])
                    ->sortByDesc('count')
                    ->values()
                    ->all(),
                // Reaching their end date in the next two weeks, soonest first.
                'ending_soon' => $live
                    ->filter(fn ($s) => $s->expires_at && $s->expires_at->lte($now->copy()->addDays(self::ENDING_SOON_DAYS)))
                    ->sortBy('expires_at')
                    ->take(6)
                    ->map(fn ($s) => [
                        'business_uuid' => $s->business?->uuid,
                        'business_name' => $s->business?->business_name,
                        'plan_name' => $s->plan?->name,
                        'is_trial' => (bool) $s->is_trial,
                        'auto_renew' => (bool) $s->auto_renew,
                        'expires_at' => $s->expires_at->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                'ending_soon_days' => self::ENDING_SOON_DAYS,
            ],
            'analytics' => [
                'business_growth' => $businessGrowth,
                'subscription_growth' => $subscriptionGrowth,
                'revenue_trend' => $revenueTrend,
            ],
            'pending_registrations' => $pendingBranches,
            'recent_transactions' => TransactionResource::collection(
                $this->billingRepository->recentSubscriptionTransactions(8)
            )->resolve(),
            'audit_logs' => $this->auditLogRepository->recent(8)->map(fn ($log) => [
                'id' => $log->id,
                'actor' => $log->user
                    ? trim("{$log->user->first_name} {$log->user->last_name}")
                    : 'System',
                'action' => $log->action,
                'summary' => \App\Support\AuditLogDescriber::summary($log),
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

    // The platform's own revenue per month, one grouped query: what
    // businesses paid for their plans, less anything given back. Payments
    // are shared with the spas' own bills (a client paying for a massage is
    // the spa's money, billing_type 'Appointment'), so only payments on a
    // subscription bill count here — the same bills as "Recent
    // Transactions" below it and the Subscribers list.
    private function revenueSeries(array $months): array
    {
        $sums = Payment::where('payment_status', 'Paid')
            ->whereHas('billing', fn ($q) => $q->where('billing_type', 'Subscription'))
            ->whereBetween('paid_at', [$months[0]['start'], $months[5]['end']])
            ->selectRaw("to_char(paid_at, 'YYYY-MM') as month, sum(amount - coalesce(refunded_amount, 0)) as total")
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

    /** A database timestamp (or null) as an ISO-8601 string with its zone, for the client to read. */
    private function isoOrNull(mixed $timestamp): ?string
    {
        return $timestamp ? Carbon::parse($timestamp)->toIso8601String() : null;
    }

    private function deltaPct(int|float $current, int|float $previous): float
    {
        if ($previous > 0) {
            return round((($current - $previous) / $previous) * 100, 1);
        }

        return $current > 0 ? 100.0 : 0.0;
    }
}
