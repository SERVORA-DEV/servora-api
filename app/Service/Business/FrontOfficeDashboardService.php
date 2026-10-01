<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Queue;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Front-desk dashboard — always scoped to "today" for the caller's one
// branch (front_officer only ever has a single accessible branch, see
// SpaBusinessRepository::branchesForUser).
//
// Money is always net of refunds: a partly refunded payment stays 'Paid'
// with refunded_amount set (BillingService::refund), so every sum here is
// amount − refunded_amount. Staff figures come from today's attendance
// (FrontOfficeAttendanceService::today), not the long-term staff.status.
class FrontOfficeDashboardService
{
    private const NET_AMOUNT = 'amount - COALESCE(refunded_amount, 0)';

    // How many of today's visits the schedule card lists; the rest are
    // counted in upcomingTotal and reachable through "View all".
    private const UPCOMING_LIMIT = 8;

    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private FrontOfficeAttendanceService $attendanceService,
    ) {
    }

    public function getOverview(User $user)
    {
        $branch = $this->spaBusinessRepository->branchesForUser($user)->first();

        if (! $branch) {
            return response()->json(['message' => 'No branch found for this account.'], 422);
        }

        // The database is a network round trip away and the front desk keeps
        // this page open, so the payload is kept briefly (short enough that
        // the queue and schedule still read as live).
        $key = sprintf('frontoffice:dashboard:%d:%s', $branch->id, Carbon::today()->toDateString());

        return Cache::remember($key, self::CACHE_TTL_SECONDS, fn () => $this->buildOverview($user, $branch));
    }

    private const CACHE_TTL_SECONDS = 15;

    private function buildOverview(User $user, $branch): array
    {
        $branchId = $branch->id;
        $today = Carbon::today();

        $visits = $this->visitCounts($branchId, $today);
        $appointmentsToday = $visits['today'];
        $appointmentsLastWeek = $visits['lastWeek'];

        $payments = $this->paymentsWeek($branchId, $today);
        $last7Days = $payments['last7Days'];
        $collectionsToday = (float) $last7Days[6]['amount'];
        $collectionsYesterday = (float) $last7Days[5]['amount'];
        $transactionsToday = $payments['transactionsToday'];
        $avgTicket = $transactionsToday > 0 ? $collectionsToday / $transactionsToday : 0.0;

        $staff = $this->staffToday($user, $branchId, $today);
        $upcoming = $this->upcomingAppointments($branchId, $today, $visits['upcoming']);

        return [
            'branchName' => $branch->branch_name,
            'stats' => [
                'appointmentsToday' => $appointmentsToday,
                'appointmentsDeltaPct' => $this->deltaPct($appointmentsToday, $appointmentsLastWeek),
                'collectionsToday' => $collectionsToday,
                'collectionsDeltaPct' => $this->deltaPct($collectionsToday, $collectionsYesterday),
                'staffOnDuty' => $staff['onDuty'],
                'staffTotal' => $staff['expected'],
                'staffOnLeave' => $staff['onLeave'],
            ],
            'upcomingAppointments' => $upcoming['rows'],
            'upcomingTotal' => $upcoming['total'],
            'queue' => $this->queueSummary($branchId, $today),
            'collections' => [
                'totalToday' => $collectionsToday,
                'changePct' => $this->deltaPct($collectionsToday, $collectionsYesterday),
                'avgTicket' => $avgTicket,
                'last7Days' => $last7Days,
            ],
            'paymentMethods' => $this->paymentMethods($payments['methodsToday'], $collectionsToday),
            'staffOnDuty' => $staff['rows'],
        ];
    }

    private function deltaPct(float $current, float $previous): float
    {
        if ($previous > 0) {
            return round((($current - $previous) / $previous) * 100, 1);
        }

        return $current > 0 ? 100.0 : 0.0;
    }

    // Today's visits, the same weekday last week (for the trend) and today's
    // still-to-come visits, in one query.
    private function visitCounts(int $branchId, Carbon $today): array
    {
        $lastWeek = $today->copy()->subWeek()->toDateString();
        $day = $today->toDateString();
        $gone = [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW];
        $open = [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_SERVICE];

        $row = Appointment::where('spa_branch_id', $branchId)
            ->whereIn(DB::raw('appointment_date::date'), [$day, $lastWeek])
            ->selectRaw(
                'sum(case when appointment_date::date = ? and status not in (?, ?) then 1 else 0 end) as today,
                 sum(case when appointment_date::date = ? and status not in (?, ?) then 1 else 0 end) as last_week,
                 sum(case when appointment_date::date = ? and status in (?, ?, ?) then 1 else 0 end) as upcoming',
                [$day, ...$gone, $lastWeek, ...$gone, $day, ...$open]
            )
            ->toBase()
            ->first();

        return [
            'today' => (int) ($row->today ?? 0),
            'lastWeek' => (int) ($row->last_week ?? 0),
            'upcoming' => (int) ($row->upcoming ?? 0),
        ];
    }

    // The week's collections (oldest first, today last) and today's split by
    // payment method, from one query grouped by day and method.
    private function paymentsWeek(int $branchId, Carbon $today): array
    {
        $from = $today->copy()->subDays(6);
        $rows = Payment::where('spa_branch_id', $branchId)
            ->where('payment_status', 'Paid')
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->selectRaw('DATE(paid_at) as day, payment_method, SUM('.self::NET_AMOUNT.') as total, COUNT(*) as n')
            ->groupBy(DB::raw('DATE(paid_at)'), 'payment_method')
            ->toBase()
            ->get();

        $byDay = $rows->groupBy(fn ($r) => substr((string) $r->day, 0, 10));
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i);
            $days[] = ['label' => $date->format('D'), 'amount' => (float) ($byDay->get($date->toDateString())?->sum('total') ?? 0)];
        }

        $todayRows = $byDay->get($today->toDateString(), collect());

        return [
            'last7Days' => $days,
            'methodsToday' => $todayRows,
            'transactionsToday' => (int) $todayRows->sum('n'),
        ];
    }

    private function paymentMethods($methodsToday, float $collectionsToday): array
    {
        return collect($methodsToday)
            ->map(function ($row) use ($collectionsToday) {
                $sum = (float) $row->total;

                return [
                    'method' => $row->payment_method,
                    'transactions' => (int) $row->n,
                    'amount' => $sum,
                    'shareOfTotalPct' => $collectionsToday > 0 ? round(($sum / $collectionsToday) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    // Same buckets as the Appointments page: a called ticket is still
    // waiting for the client to come up.
    private function queueSummary(int $branchId, Carbon $today): array
    {
        $counts = Queue::where('spa_branch_id', $branchId)
            ->where('appointment_date', $today->toDateString())
            ->selectRaw('queue_status, COUNT(*) as n')
            ->groupBy('queue_status')
            ->pluck('n', 'queue_status');

        return [
            'waiting' => (int) (($counts['Waiting'] ?? 0) + ($counts['Called'] ?? 0)),
            'inService' => (int) ($counts['Serving'] ?? 0),
            'completedToday' => (int) ($counts['Completed'] ?? 0),
        ];
    }

    private function upcomingAppointments(int $branchId, Carbon $today, int $total): array
    {
        $query = Appointment::where('spa_branch_id', $branchId)
            ->whereDate('appointment_date', $today)
            ->whereIn('status', [
                Appointment::STATUS_SCHEDULED,
                Appointment::STATUS_CHECKED_IN,
                Appointment::STATUS_IN_SERVICE,
            ]);

        $rows = $query->with([
            'client',
            'services.serviceVariant.service',
            'services.therapistAssignments' => fn ($q) => $q->where('assignment_status', '!=', 'Cancelled'),
            'services.therapistAssignments.staff',
            'services.therapistAssignments.facility',
            'queue',
        ])
            ->orderBy('appointment_time')
            ->limit(self::UPCOMING_LIMIT)
            ->get()
            ->map(fn (Appointment $appointment) => $this->mapUpcoming($appointment))
            ->all();

        return ['rows' => $rows, 'total' => $total];
    }

    private function mapUpcoming(Appointment $appointment): array
    {
        $services = $appointment->services;

        $serviceLabel = $services->count() > 1
            ? $services->count().' services'
            : ($services->first()?->serviceVariant?->service?->name ?? 'Service');

        $assignments = $services->flatMap(fn ($service) => $service->therapistAssignments);

        $therapistNames = $assignments->pluck('staff')->filter()
            ->map(fn ($staff) => trim("{$staff->first_name} {$staff->last_name}"))
            ->unique()->values();
        $roomNames = $assignments->pluck('facility')->filter()
            ->pluck('name')->unique()->values();

        return [
            'uuid' => $appointment->uuid,
            'time' => substr($appointment->appointment_time, 0, 5),
            'clientName' => trim("{$appointment->client?->first_name} {$appointment->client?->last_name}"),
            'service' => $serviceLabel,
            'therapistName' => $therapistNames->count() > 1 ? 'Multiple' : ($therapistNames->first() ?? 'Unassigned'),
            'room' => $roomNames->first() ?? '',
            'channel' => $appointment->appointment_type === 'Walk-in' ? 'Walk-in' : 'Reservation',
            'status' => $appointment->status,
            'queueStatus' => $appointment->queue?->queue_status,
        ];
    }

    // Today's roster from attendance: who is in, who is expected, who is
    // off on leave. A therapist is busy only with a service in progress
    // today at this branch.
    private function staffToday(User $user, int $branchId, Carbon $today): array
    {
        $roster = $this->attendanceService->today($user);
        $rows = is_array($roster) ? $roster['data'] : [];

        $busyUuids = TherapistAssignment::where('assignment_status', 'In Progress')
            ->whereHas('appointmentService.appointment', fn ($q) => $q
                ->where('spa_branch_id', $branchId)
                ->whereDate('appointment_date', $today))
            ->join('staff', 'staff.id', '=', 'therapist_assignments.staff_id')
            ->pluck('staff.uuid')
            ->all();

        $onDuty = 0;
        $expected = 0;
        $onLeave = 0;
        $list = [];

        foreach ($rows as $row) {
            $in = $row['check_in_at'] && ! $row['check_out_at'];
            $leave = $row['status'] === 'On Leave';
            $scheduled = $row['scheduled_start'] && ! $row['is_day_off'];

            if ($leave) {
                $onLeave++;
            }
            if ($in) {
                $onDuty++;
            }
            if ($in || ($scheduled && ! $leave && ! $row['check_out_at'])) {
                $expected++;
            }

            $state = match (true) {
                $leave => 'on_leave',
                $in && in_array($row['staff_uuid'], $busyUuids, true) => 'busy',
                $in => 'available',
                $row['check_out_at'] !== null => 'checked_out',
                $row['status'] === 'Absent' => 'absent',
                (bool) $scheduled => 'not_in',
                default => null,
            };
            if (! $state) {
                continue; // day off / not scheduled — not part of today
            }

            $list[] = [
                'uuid' => $row['staff_uuid'],
                'name' => trim($row['first_name'].' '.$row['last_name']),
                'role' => $row['role'],
                'roleLabel' => $this->roleLabel($row['role']),
                'status' => $state,
                'checkInAt' => $row['check_in_at'],
                'late' => $row['status'] === 'Late',
                'coveringFor' => $row['covering_for_name'],
            ];
        }

        $order = ['busy' => 0, 'available' => 1, 'not_in' => 2, 'on_leave' => 3, 'absent' => 4, 'checked_out' => 5];
        usort($list, fn ($a, $b) => [$order[$a['status']], $a['name']] <=> [$order[$b['status']], $b['name']]);

        return ['rows' => $list, 'onDuty' => $onDuty, 'expected' => $expected, 'onLeave' => $onLeave];
    }

    private function roleLabel(?string $role): string
    {
        return match ($role) {
            'therapist' => 'Therapist',
            'manager' => 'Manager',
            'frontdesk', 'front_officer' => 'Front desk',
            default => ucfirst(str_replace('_', ' ', (string) $role)),
        };
    }
}
