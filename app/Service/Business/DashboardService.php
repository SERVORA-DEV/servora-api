<?php

namespace App\Service\Business;

use App\Models\Billing;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class DashboardService
{
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(SpaBusinessRepository $spaBusinessRepository)
    {
        $this->spaBusinessRepository = $spaBusinessRepository;
    }

    /**
     * Real-data-only overview: staff roster (Staff), branch scope, and
     * revenue/billing (Payment/Billing) — the only domain models that exist
     * today. Appointments, walk-in queue, and top-services have no backing
     * model (no Appointment/Queue/service-catalog table), so those stay
     * explicitly empty and flagged via `comingSoon` rather than faking
     * plausible-looking numbers.
     *
     * Branch scope is business_owner = every branch of their business,
     * manager/front_officer = only their own staff record's branch
     * — see SpaBusinessRepository::branchesForUser, the same primitive the
     * staff/branch listing endpoints use.
     */
    public function getOverview(User $user, string $scope = 'overview')
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // The database is a network round trip away, so the overview is built
        // from a handful of aggregate queries and kept for a short while —
        // revisits, scope toggles back and forth and several staff on the
        // same business are served from cache. An owner sees every branch;
        // anyone else only their own, so the key carries that branch and an
        // owner and a branch manager never share an entry.
        $scopeKey = $user->role === 'business_owner' ? 'all' : (string) ($user->staff?->spa_branch_id ?? 'none');
        $key = sprintf('business:dashboard:%s:%d:%s:%s', $user->role, $business->id, $scopeKey, $scope);

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($user, $scope) {
            $branches = $this->spaBusinessRepository->branchesForUser($user);

            return $this->buildOverview($user, $branches, $branches->pluck('id')->all(), $scope);
        });
    }

    private const CACHE_TTL_SECONDS = 30;

    private function buildOverview(User $user, $branches, array $branchIds, string $scope): array
    {
        [$periodStart, $periodEnd, $prevStart, $prevEnd, $periodLabel] = $this->periodFor($scope);
        $payments = $this->paymentTotals($branchIds, $periodStart, $periodEnd, $prevStart, $prevEnd);

        $revenueTotal = $payments['current'];
        $revenuePrev = $payments['previous'];

        $revenueDeltaPct = $revenuePrev > 0
            ? round((($revenueTotal - $revenuePrev) / $revenuePrev) * 100, 1)
            : ($revenueTotal > 0 ? 100.0 : 0.0);

        $staff = $this->staffRoster($branchIds);

        return [
            'scope' => $scope,
            'branchScope' => $this->branchScope($user, $branches),
            'stats' => [
                'activeStaff' => $staff->where('status', 'active')->count(),
                'totalStaff' => $staff->count(),
                'staffOnLeave' => $staff->where('status', 'on-leave')->count(),
                'revenue' => [
                    'total' => $revenueTotal,
                    'deltaPct' => $revenueDeltaPct,
                    'periodLabel' => $periodLabel,
                ],
                'outstandingBilling' => $this->outstandingBilling($branchIds),
            ],
            'revenueLast7Days' => $payments['last7Days'],
            'staffAvailability' => $this->staffAvailability($staff),

            // No Appointment/Queue/service-catalog model exists yet — these
            // stay honestly empty rather than fabricated, and comingSoon
            // tells the frontend to render "Coming soon" instead of "0".
            'upcomingReservations' => [],
            'walkIns' => ['waiting' => 0, 'inService' => 0, 'completedToday' => 0],
            'topServices' => [],
            'comingSoon' => ['appointments', 'walk_ins', 'top_services'],
        ];
    }

    // Revenue for this period, the previous one and each of the last 7 days,
    // in one query (it used to be nine).
    private function paymentTotals(array $branchIds, $periodStart, $periodEnd, $prevStart, $prevEnd): array
    {
        $days = [];
        $sums = [
            'sum(case when paid_at between ? and ? then amount else 0 end) as current',
            'sum(case when paid_at between ? and ? then amount else 0 end) as previous',
        ];
        $bindings = [$periodStart, $periodEnd, $prevStart, $prevEnd];

        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $days[] = $date;
            $sums[] = "sum(case when paid_at between ? and ? then amount else 0 end) as d{$i}";
            $bindings[] = $date->copy()->startOfDay();
            $bindings[] = $date->copy()->endOfDay();
        }

        $row = Payment::whereIn('spa_branch_id', $branchIds)
            ->where('payment_status', 'Paid')
            ->selectRaw(implode(', ', $sums), $bindings)
            ->toBase()
            ->first();

        return [
            'current' => (float) ($row->current ?? 0),
            'previous' => (float) ($row->previous ?? 0),
            'last7Days' => array_map(fn (Carbon $date, int $i) => [
                'label' => $date->format('D'),
                'amount' => (float) ($row->{'d' . (6 - $i)} ?? 0),
            ], $days, array_keys($days)),
        ];
    }

    // Every staff member at the scoped branches (one query) — the counts and
    // the availability roster are both worked out from it.
    private function staffRoster(array $branchIds)
    {
        return Staff::whereIn('spa_branch_id', $branchIds)
            ->get(['id', 'first_name', 'last_name', 'role', 'status']);
    }

    private function outstandingBilling(array $branchIds): array
    {
        $row = Billing::whereIn('spa_branch_id', $branchIds)
            ->whereIn('status', ['Pending', 'Overdue'])
            ->selectRaw('coalesce(sum(amount), 0) as total, count(*) as count')
            ->toBase()
            ->first();

        return [
            'total' => (float) $row->total,
            'count' => (int) $row->count,
        ];
    }

    // Only ever emits available/on_leave — both real, straight from
    // Staff.status. 'busy' isn't emitted since there's no appointment data
    // to derive it from; terminated/inactive staff are left off the roster
    // entirely since they're not part of an "availability" view.
    private function staffAvailability($staff): array
    {
        return $staff
            ->whereIn('status', ['active', 'on-leave'])
            ->map(fn (Staff $staff) => [
                'id' => $staff->id,
                'name' => trim("{$staff->first_name} {$staff->last_name}"),
                'role' => $staff->role,
                'status' => $staff->status === 'on-leave' ? 'on_leave' : 'available',
            ])
            ->values()
            ->all();
    }

    private function branchScope(User $user, $branches): array
    {
        $count = $branches->count();

        return [
            'mode' => $user->role === 'business_owner' ? 'all' : 'single',
            'count' => $count,
            'branchName' => $count === 1 ? $branches->first()->branch_name : null,
        ];
    }

    private function periodFor(string $scope): array
    {
        $now = Carbon::now();

        if ($scope === 'today') {
            return [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
                'Today',
            ];
        }

        // overview — trailing 30 days vs the 30 days before that.
        return [
            $now->copy()->subDays(29)->startOfDay(),
            $now->copy()->endOfDay(),
            $now->copy()->subDays(59)->startOfDay(),
            $now->copy()->subDays(30)->endOfDay(),
            'Last 30 days',
        ];
    }
}
