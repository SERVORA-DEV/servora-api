<?php

namespace App\Service\Business;

use App\Models\Billing;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use Illuminate\Support\Carbon;

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
     * manager/front_officer = only their own AccountBranch-assigned branch
     * — see SpaBusinessRepository::branchesForUser, the same primitive the
     * staff/branch listing endpoints use.
     */
    public function getOverview(User $user, string $scope = 'overview')
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $branches = $this->spaBusinessRepository->branchesForUser($user);
        $branchIds = $branches->pluck('id')->all();

        [$periodStart, $periodEnd, $prevStart, $prevEnd, $periodLabel] = $this->periodFor($scope);

        $revenueTotal = (float) Payment::whereIn('spa_branch_id', $branchIds)
            ->where('payment_status', 'Paid')
            ->whereBetween('paid_at', [$periodStart, $periodEnd])
            ->sum('amount');

        $revenuePrev = (float) Payment::whereIn('spa_branch_id', $branchIds)
            ->where('payment_status', 'Paid')
            ->whereBetween('paid_at', [$prevStart, $prevEnd])
            ->sum('amount');

        $revenueDeltaPct = $revenuePrev > 0
            ? round((($revenueTotal - $revenuePrev) / $revenuePrev) * 100, 1)
            : ($revenueTotal > 0 ? 100.0 : 0.0);

        return [
            'scope' => $scope,
            'branchScope' => $this->branchScope($user, $branches),
            'stats' => [
                'activeStaff' => (clone $this->staffQuery($branchIds))->where('status', 'active')->count(),
                'totalStaff' => (clone $this->staffQuery($branchIds))->count(),
                'staffOnLeave' => (clone $this->staffQuery($branchIds))->where('status', 'on-leave')->count(),
                'revenue' => [
                    'total' => $revenueTotal,
                    'deltaPct' => $revenueDeltaPct,
                    'periodLabel' => $periodLabel,
                ],
                'outstandingBilling' => $this->outstandingBilling($branchIds),
            ],
            'revenueLast7Days' => $this->revenueLast7Days($branchIds),
            'staffAvailability' => $this->staffAvailability($branchIds),

            // No Appointment/Queue/service-catalog model exists yet — these
            // stay honestly empty rather than fabricated, and comingSoon
            // tells the frontend to render "Coming soon" instead of "0".
            'upcomingReservations' => [],
            'walkIns' => ['waiting' => 0, 'inService' => 0, 'completedToday' => 0],
            'topServices' => [],
            'comingSoon' => ['appointments', 'walk_ins', 'top_services'],
        ];
    }

    private function staffQuery(array $branchIds)
    {
        return Staff::whereIn('spa_branch_id', $branchIds);
    }

    private function outstandingBilling(array $branchIds): array
    {
        $query = Billing::whereIn('spa_branch_id', $branchIds)->whereIn('status', ['Pending', 'Overdue']);

        return [
            'total' => (float) (clone $query)->sum('amount'),
            'count' => (clone $query)->count(),
        ];
    }

    private function revenueLast7Days(array $branchIds): array
    {
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $amount = (float) Payment::whereIn('spa_branch_id', $branchIds)
                ->where('payment_status', 'Paid')
                ->whereDate('paid_at', $date->toDateString())
                ->sum('amount');

            $days[] = ['label' => $date->format('D'), 'amount' => $amount];
        }

        return $days;
    }

    // Only ever emits available/on_leave — both real, straight from
    // Staff.status. 'busy' isn't emitted since there's no appointment data
    // to derive it from; terminated/inactive staff are left off the roster
    // entirely since they're not part of an "availability" view.
    private function staffAvailability(array $branchIds): array
    {
        return Staff::whereIn('spa_branch_id', $branchIds)
            ->whereIn('status', ['active', 'on-leave'])
            ->get()
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
