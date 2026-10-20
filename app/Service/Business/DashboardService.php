<?php

namespace App\Service\Business;

use App\Models\Addon;
use App\Models\Billing;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Support\History;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(SpaBusinessRepository $spaBusinessRepository, private ServingNow $servingNow)
    {
        $this->spaBusinessRepository = $spaBusinessRepository;
    }

    /**
     * The owner's and manager's dashboard, from what the branches actually
     * did and are doing:
     *
     *   the money     revenue for the period (and the one before, for the
     *                 change), each of the last 7 days, bills still unpaid,
     *                 and the services that sold most (Payment, Billing,
     *                 completed appointment services);
     *   today         every visit of the day by where it stands, who is in
     *                 the spa right now, and what is coming up next
     *                 (Appointment);
     *   the staff     who is timed in, who is with a client and who is away
     *                 (Staff, Attendance, ServingNow);
     *   the branches  the same figures branch by branch, for an owner with
     *                 more than one.
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
        // owner and a branch manager never share an entry. The page asks
        // again every 20 seconds: kept for less than that, so each of its
        // checks gets a fresh answer.
        $scopeKey = $user->role === 'business_owner' ? 'all' : (implode('-', $this->spaBusinessRepository->branchIdsForUser($user)) ?: 'none');
        $key = sprintf('business:dashboard:%s:%d:%s:%s', $user->role, $business->id, $scopeKey, $scope);

        $branches = null;
        $load = function () use (&$branches, $user) {
            return $branches ??= $this->spaBusinessRepository->branchesForUser($user);
        };

        // Two answers kept for different lengths of time. What happens by the
        // minute (visits, who is in, who is with a client) is asked for again
        // on every check the page makes. The money moves slower and costs as
        // many queries again, so it is kept a little longer.
        $money = Cache::remember("{$key}:money", self::MONEY_TTL_SECONDS, function () use ($load, $scope) {
            $branches = $load();

            return $this->money($branches, $branches->pluck('id')->all(), $scope);
        });
        $live = Cache::remember("{$key}:live", self::CACHE_TTL_SECONDS, function () use ($load, $user) {
            $branches = $load();

            return $this->live($user, $branches, $branches->pluck('id')->all());
        });

        // One row per branch, for an owner with several: the two halves
        // joined on the branch.
        $perBranch = [];
        if (count($live['branches']) > 1) {
            foreach ($live['branches'] as $id => $branch) {
                $perBranch[] = $branch + ['revenue' => (float) ($money['revenueByBranch'][$id] ?? 0)];
            }
        }

        return [
            'scope' => $scope,
            'branchScope' => $live['branchScope'],
            'stats' => [
                'activeStaff' => $live['staff']['active'],
                'totalStaff' => $live['staff']['total'],
                'staffOnLeave' => $live['staff']['onLeave'],
                'staffOnDuty' => $live['staff']['onDuty'],
                'staffInService' => $live['staff']['inService'],
                'revenue' => $money['revenue'],
                'outstandingBilling' => $money['outstandingBilling'],
            ],
            'revenueLast7Days' => $money['revenueLast7Days'],
            'topServices' => $money['topServices'],
            'today' => $live['today'],
            // Kept for the screens that read the old name: waiting = checked
            // in and not started, whether or not the branch hands out queue
            // numbers.
            'walkIns' => [
                'waiting' => $live['today']['appointments']['checkedIn'],
                'inService' => $live['today']['appointments']['inService'],
                'completedToday' => $live['today']['appointments']['completed'],
            ],
            'upcomingReservations' => $live['upcoming'],
            'staffAvailability' => $live['staff']['roster'],
            'branches' => $perBranch,
            // Everything above is real now; nothing is a placeholder.
            'comingSoon' => [],
        ];
    }

    private const CACHE_TTL_SECONDS = 15;
    private const MONEY_TTL_SECONDS = 60;

    // How many visits the "coming up" list shows, and services the top list.
    private const UPCOMING_LIMIT = 8;
    private const TOP_SERVICES_LIMIT = 5;

    // ── The money ───────────────────────────────────────────────────────

    private function money($branches, array $branchIds, string $scope): array
    {
        [$periodStart, $periodEnd, $prevStart, $prevEnd, $periodLabel] = $this->periodFor($scope);
        $payments = $this->paymentTotals($branchIds, $periodStart, $periodEnd, $prevStart, $prevEnd);

        $revenueTotal = $payments['current'];
        $revenuePrev = $payments['previous'];

        $revenueDeltaPct = $revenuePrev > 0
            ? round((($revenueTotal - $revenuePrev) / $revenuePrev) * 100, 1)
            : ($revenueTotal > 0 ? 100.0 : 0.0);

        return [
            'revenue' => [
                'total' => $revenueTotal,
                'previous' => $revenuePrev,
                'deltaPct' => $revenueDeltaPct,
                'periodLabel' => $periodLabel,
            ],
            'revenueLast7Days' => $payments['last7Days'],
            'outstandingBilling' => $this->outstandingBilling($branchIds),
            'topServices' => $this->topServices($branchIds, $periodStart, $periodEnd),
            // Only worth a query when there is more than one branch to compare.
            'revenueByBranch' => count($branchIds) > 1 ? $this->revenueByBranch($branchIds, $periodStart, $periodEnd) : [],
        ];
    }

    // The services completed most in the period, with what they brought in
    // and their share of everything completed (one query: the window sum
    // gives the total alongside the top few). A service keeps the name it
    // had when it was done (App\Support\History).
    private function topServices(array $branchIds, $periodStart, $periodEnd): array
    {
        if (! $branchIds) {
            return [];
        }
        $name = History::ready() ? 'coalesce(s.service_name, sv.name)' : 'sv.name';

        return DB::table('appointment_services as s')
            ->join('appointments as a', 'a.id', '=', 's.appointment_id')
            ->leftJoin('service_variants as v', 'v.id', '=', 's.service_variant_id')
            ->leftJoin('services as sv', 'sv.id', '=', 'v.service_id')
            ->whereIn('a.spa_branch_id', $branchIds)
            ->whereNull('a.deleted_at')
            ->where('s.status', 'Completed')
            // An add-on is part of the service it rides with, not one of its own.
            ->when(Addon::ready(), fn ($q) => $q->whereNull('s.parent_appointment_service_id'))
            ->whereBetween('a.appointment_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->groupByRaw($name)
            ->selectRaw("{$name} as name, count(*) as bookings, coalesce(sum(s.subtotal), 0) as revenue, sum(count(*)) over () as total")
            ->orderByDesc('bookings')
            ->orderByDesc('revenue')
            ->limit(self::TOP_SERVICES_LIMIT)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name ?? 'Service',
                'bookings' => (int) $row->bookings,
                'revenue' => (float) $row->revenue,
                'shareOfTotalPct' => $row->total > 0 ? round($row->bookings / $row->total * 100, 1) : 0.0,
            ])
            ->all();
    }

    /** @return array<int, float> paid in the period, by branch id */
    private function revenueByBranch(array $branchIds, $periodStart, $periodEnd): array
    {
        return Payment::whereIn('spa_branch_id', $branchIds)
            ->where('payment_status', 'Paid')
            ->whereBetween('paid_at', [$periodStart, $periodEnd])
            ->groupBy('spa_branch_id')
            ->selectRaw('spa_branch_id, coalesce(sum(amount), 0) as total')
            ->toBase()
            ->pluck('total', 'spa_branch_id')
            ->map(fn ($total) => (float) $total)
            ->all();
    }

    // ── Today ───────────────────────────────────────────────────────────

    private function live(User $user, $branches, array $branchIds): array
    {
        $today = Carbon::now()->toDateString();
        $visits = $this->visitsToday($branchIds, $today);
        $staff = $this->staffToday($branchIds, $today);

        $perBranch = [];
        foreach ($branches as $branch) {
            $counts = $visits['byBranch'][$branch->id] ?? [];
            $people = $staff['byBranch'][$branch->id] ?? ['onDuty' => 0, 'active' => 0];
            $perBranch[$branch->id] = [
                'uuid' => $branch->uuid,
                'name' => $branch->branch_name,
                'visitsToday' => $this->booked($counts),
                'inSpaNow' => ($counts['checked_in'] ?? 0) + ($counts['in_service'] ?? 0),
                'completedToday' => $counts['completed'] ?? 0,
                'staffOnDuty' => $people['onDuty'],
                'staffActive' => $people['active'],
            ];
        }

        return [
            'branchScope' => $this->branchScope($user, $branches),
            'today' => ['date' => $today, 'appointments' => $visits['totals']],
            'upcoming' => $this->upcoming($branchIds, $today, $branches->count() > 1),
            'staff' => $staff,
            'branches' => $perBranch,
        ];
    }

    // Today's visits, counted by where each stands — per branch, and added
    // up (one query).
    private function visitsToday(array $branchIds, string $today): array
    {
        $byBranch = [];
        if ($branchIds) {
            $rows = DB::table('appointments')
                ->whereIn('spa_branch_id', $branchIds)
                ->whereDate('appointment_date', $today)
                ->whereNull('deleted_at')
                ->groupBy('spa_branch_id', 'status')
                ->selectRaw('spa_branch_id, status, count(*) as n')
                ->get();
            foreach ($rows as $row) {
                $byBranch[(int) $row->spa_branch_id][$row->status] = (int) $row->n;
            }
        }

        $sum = fn (string $status) => array_sum(array_map(fn ($counts) => $counts[$status] ?? 0, $byBranch));
        $all = [];
        foreach (['scheduled', 'checked_in', 'in_service', 'completed', 'cancelled', 'no_show'] as $status) {
            $all[$status] = $sum($status);
        }

        return [
            'byBranch' => $byBranch,
            'totals' => [
                // Booked for today and still on: everything but the ones that
                // were cancelled or never came.
                'total' => $this->booked($all),
                'scheduled' => $all['scheduled'],
                'checkedIn' => $all['checked_in'],
                'inService' => $all['in_service'],
                'completed' => $all['completed'],
                'cancelled' => $all['cancelled'],
                'noShow' => $all['no_show'],
            ],
        ];
    }

    private function booked(array $counts): int
    {
        return ($counts['scheduled'] ?? 0) + ($counts['checked_in'] ?? 0) + ($counts['in_service'] ?? 0) + ($counts['completed'] ?? 0);
    }

    // What is coming up: visits from today on that have not finished, soonest
    // first, with what each is for and who is doing it (three queries: the
    // visits, their services, who is on them).
    private function upcoming(array $branchIds, string $today, bool $severalBranches): array
    {
        if (! $branchIds) {
            return [];
        }

        $visits = DB::table('appointments as a')
            ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
            ->join('spa_branches as b', 'b.id', '=', 'a.spa_branch_id')
            ->whereIn('a.spa_branch_id', $branchIds)
            ->whereNull('a.deleted_at')
            ->whereDate('a.appointment_date', '>=', $today)
            ->whereIn('a.status', ['scheduled', 'checked_in', 'in_service'])
            ->orderBy('a.appointment_date')
            ->orderBy('a.appointment_time')
            ->limit(self::UPCOMING_LIMIT)
            ->get([
                'a.id', 'a.uuid', 'a.appointment_date', 'a.appointment_time', 'a.appointment_type',
                'a.service_location', 'a.status', 'c.first_name', 'c.last_name', 'b.branch_name',
            ]);
        if ($visits->isEmpty()) {
            return [];
        }

        $own = History::ready();
        $services = DB::table('appointment_services as s')
            ->leftJoin('service_variants as v', 'v.id', '=', 's.service_variant_id')
            ->leftJoin('services as sv', 'sv.id', '=', 'v.service_id')
            ->whereIn('s.appointment_id', $visits->pluck('id')->all())
            ->where('s.status', '!=', 'Cancelled')
            ->when(Addon::ready(), fn ($q) => $q->whereNull('s.parent_appointment_service_id'))
            ->orderBy('s.sort_order')
            ->get(['s.id', 's.appointment_id', DB::raw(($own ? 'coalesce(s.service_name, sv.name)' : 'sv.name') . ' as name')]);

        $people = DB::table('therapist_assignments as ta')
            ->leftJoin('staff as st', 'st.id', '=', 'ta.staff_id')
            ->leftJoin('facilities as f', 'f.id', '=', 'ta.facility_id')
            ->whereIn('ta.appointment_service_id', $services->pluck('id')->all() ?: [0])
            ->where('ta.assignment_status', '!=', 'Cancelled')
            ->get([
                'ta.appointment_service_id',
                DB::raw(($own ? "coalesce(ta.staff_name, nullif(trim(concat(st.first_name, ' ', st.last_name)), ''))" : "nullif(trim(concat(st.first_name, ' ', st.last_name)), '')") . ' as staff'),
                DB::raw(($own ? 'coalesce(ta.facility_name, f.name)' : 'f.name') . ' as room'),
            ])
            ->groupBy('appointment_service_id');

        return $visits->map(function ($visit) use ($services, $people, $today, $severalBranches) {
            $mine = $services->where('appointment_id', $visit->id)->values();
            $onThem = $mine->flatMap(fn ($service) => $people->get($service->id, collect()));
            $staff = $onThem->pluck('staff')->filter()->unique()->values();
            $date = Carbon::parse($visit->appointment_date)->toDateString();

            return [
                'id' => (int) $visit->id,
                'uuid' => $visit->uuid,
                'date' => $date,
                'isToday' => $date === $today,
                'time' => substr((string) $visit->appointment_time, 0, 5),
                'clientName' => trim(($visit->first_name ?? '') . ' ' . ($visit->last_name ?? '')) ?: 'Walk-in client',
                'service' => $mine->first()->name ?? 'Service',
                // How many more services the visit has, beyond the one named.
                'moreServices' => max(0, $mine->count() - 1),
                'therapistName' => $staff->first(),
                'moreStaff' => max(0, $staff->count() - 1),
                'room' => $onThem->pluck('room')->filter()->first(),
                'channel' => $visit->service_location === 'Home Service'
                    ? 'Home Service'
                    : ($visit->appointment_type === 'Walk-in' ? 'Walk-in' : 'Reservation'),
                'status' => $visit->status,
                // Only said when there is more than one branch to tell apart.
                'branchName' => $severalBranches ? $visit->branch_name : null,
            ];
        })->all();
    }

    // ── The staff ───────────────────────────────────────────────────────

    // Everyone at the scoped branches and where they stand today: with a
    // client, timed in, timed out, not in yet, or on leave (three queries:
    // the roster, today's attendance, who is serving right now).
    private function staffToday(array $branchIds, string $today): array
    {
        $roster = Staff::whereIn('spa_branch_id', $branchIds)
            ->whereIn('status', ['active', 'on-leave'])
            ->orderBy('first_name')
            ->get(['id', 'uuid', 'spa_branch_id', 'first_name', 'last_name', 'role', 'status']);
        $ids = $roster->pluck('id')->all();

        $attendance = $ids
            ? DB::table('attendances')->whereIn('staff_id', $ids)->whereDate('attendance_date', $today)
                ->get(['staff_id', 'check_in_at', 'check_out_at', 'status'])->keyBy('staff_id')
            : collect();
        $serving = $this->servingNow->forStaff($ids);

        $byBranch = [];
        $rows = $roster->map(function (Staff $staff) use ($attendance, $serving, &$byBranch) {
            $day = $attendance->get($staff->id);
            $with = $serving[$staff->id] ?? null;

            [$state, $detail] = match (true) {
                $staff->status === 'on-leave' || ($day?->status === 'On Leave') => ['on_leave', null],
                $with !== null => ['in_service', trim(($with['client'] ?? 'A client') . ' · ' . ($with['service'] ?? 'Service'), ' ·')],
                $day?->check_out_at !== null => ['timed_out', Carbon::parse($day->check_out_at)->format('H:i')],
                $day?->check_in_at !== null => ['on_duty', Carbon::parse($day->check_in_at)->format('H:i')],
                default => ['not_in', null],
            };

            $byBranch[$staff->spa_branch_id] ??= ['onDuty' => 0, 'active' => 0];
            $byBranch[$staff->spa_branch_id]['active'] += $staff->status === 'active' ? 1 : 0;
            $byBranch[$staff->spa_branch_id]['onDuty'] += in_array($state, ['on_duty', 'in_service'], true) ? 1 : 0;

            return [
                'id' => $staff->id,
                'uuid' => $staff->uuid,
                'name' => trim("{$staff->first_name} {$staff->last_name}"),
                'role' => $staff->role,
                'status' => $state,
                // Who they are with, or the time they timed in or out ('HH:MM').
                'detail' => $detail,
            ];
        });

        // The order a manager looks for people in: busy, here, not here.
        $order = ['in_service' => 0, 'on_duty' => 1, 'not_in' => 2, 'timed_out' => 3, 'on_leave' => 4];
        $sorted = $rows->sortBy(fn ($row) => $order[$row['status']])->values();

        return [
            'total' => $roster->count(),
            'active' => $roster->where('status', 'active')->count(),
            'onLeave' => $rows->where('status', 'on_leave')->count(),
            'onDuty' => $rows->whereIn('status', ['on_duty', 'in_service'])->count(),
            'inService' => $rows->where('status', 'in_service')->count(),
            'roster' => $sorted->all(),
            'byBranch' => $byBranch,
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
