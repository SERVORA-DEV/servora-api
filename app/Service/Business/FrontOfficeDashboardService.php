<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Queue;
use App\Models\Staff;
use App\Models\TherapistAssignment;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use Illuminate\Support\Carbon;

// Front-desk dashboard — always scoped to "today" for the caller's one
// branch (front_officer only ever has a single accessible branch, see
// SpaBusinessRepository::branchesForUser), unlike the owner dashboard's
// 30-day/today period toggle. `scope` is accepted and echoed back for the
// frontend's Overview/Today's Summary tabs, but every figure here already
// reads as "today" (matches the fixed "Today's Appointments"/"Today's
// Collections" labels those tabs sit above) — there's no second period to
// switch between yet.
class FrontOfficeDashboardService
{
    private SpaBusinessRepository $spaBusinessRepository;

    public function __construct(SpaBusinessRepository $spaBusinessRepository)
    {
        $this->spaBusinessRepository = $spaBusinessRepository;
    }

    public function getOverview(User $user, string $scope = 'overview')
    {
        $branch = $this->spaBusinessRepository->branchesForUser($user)->first();

        if (! $branch) {
            return response()->json(['message' => 'No branch found for this account.'], 422);
        }

        $branchId = $branch->id;
        $today = Carbon::today();

        $appointmentsToday = Appointment::where('spa_branch_id', $branchId)
            ->whereDate('appointment_date', $today)
            ->count();
        $appointmentsLastWeek = Appointment::where('spa_branch_id', $branchId)
            ->whereDate('appointment_date', $today->copy()->subWeek())
            ->count();

        $collectionsToday = (float) Payment::where('spa_branch_id', $branchId)
            ->where('payment_status', 'Paid')
            ->whereDate('paid_at', $today)
            ->sum('amount');
        $collectionsYesterday = (float) Payment::where('spa_branch_id', $branchId)
            ->where('payment_status', 'Paid')
            ->whereDate('paid_at', $today->copy()->subDay())
            ->sum('amount');

        $last7Days = $this->last7Days($branchId, $today);
        $trailingAvg = count($last7Days) > 0
            ? array_sum(array_column($last7Days, 'amount')) / count($last7Days)
            : 0.0;

        $paymentsToday = Payment::where('spa_branch_id', $branchId)
            ->where('payment_status', 'Paid')
            ->whereDate('paid_at', $today)
            ->get();
        $avgTicket = $paymentsToday->count() > 0 ? $collectionsToday / $paymentsToday->count() : 0.0;

        $activeStaff = Staff::where('spa_branch_id', $branchId)->where('status', 'active')->count();
        $onLeaveStaff = Staff::where('spa_branch_id', $branchId)->where('status', 'on-leave')->count();

        return [
            'scope' => $scope,
            'branchName' => $branch->branch_name,
            'stats' => [
                'appointmentsToday' => $appointmentsToday,
                'appointmentsDeltaPct' => $this->deltaPct($appointmentsToday, $appointmentsLastWeek),
                'collectionsToday' => $collectionsToday,
                'collectionsDeltaPct' => $this->deltaPct($collectionsToday, $trailingAvg),
                'staffOnDuty' => $activeStaff,
                'staffTotal' => $activeStaff + $onLeaveStaff,
                'staffOnLeave' => $onLeaveStaff,
            ],
            'upcomingAppointments' => $this->upcomingAppointments($branchId, $today),
            'queue' => $this->queueSummary($branchId, $today),
            'collections' => [
                'totalToday' => $collectionsToday,
                // No revenue-target field exists on SpaBranch — stays
                // honestly 0 rather than fabricated; CollectionsSummary.vue
                // already hides the target line when this is 0.
                'targetToday' => 0,
                'changePct' => $this->deltaPct($collectionsToday, $collectionsYesterday),
                'avgTicket' => $avgTicket,
                'last7Days' => $last7Days,
            ],
            'paymentMethods' => $this->paymentMethods($paymentsToday, $collectionsToday),
            'staffOnDuty' => $this->staffOnDuty($branchId),
        ];
    }

    private function deltaPct(float $current, float $previous): float
    {
        if ($previous > 0) {
            return round((($current - $previous) / $previous) * 100, 1);
        }

        return $current > 0 ? 100.0 : 0.0;
    }

    private function last7Days(int $branchId, Carbon $today): array
    {
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i);
            $amount = (float) Payment::where('spa_branch_id', $branchId)
                ->where('payment_status', 'Paid')
                ->whereDate('paid_at', $date)
                ->sum('amount');

            $days[] = ['label' => $date->format('D'), 'amount' => $amount];
        }

        return $days;
    }

    private function paymentMethods($paymentsToday, float $collectionsToday): array
    {
        return $paymentsToday->groupBy('payment_method')
            ->map(function ($group, $method) use ($collectionsToday) {
                $sum = (float) $group->sum('amount');

                return [
                    'method' => $method,
                    'transactions' => $group->count(),
                    'shareOfTotalPct' => $collectionsToday > 0 ? round(($sum / $collectionsToday) * 100, 1) : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    private function queueSummary(int $branchId, Carbon $today): array
    {
        $base = Queue::where('spa_branch_id', $branchId)->where('appointment_date', $today->toDateString());

        return [
            'waiting' => (clone $base)->where('queue_status', 'Waiting')->count(),
            'inService' => (clone $base)->where('queue_status', 'Serving')->count(),
            'completedToday' => (clone $base)->where('queue_status', 'Completed')->count(),
        ];
    }

    private function upcomingAppointments(int $branchId, Carbon $today): array
    {
        return Appointment::with([
            'client',
            'services.serviceVariant.service',
            'services.therapistAssignments.staff',
            'services.therapistAssignments.facility',
            'queue',
        ])
            ->where('spa_branch_id', $branchId)
            ->whereDate('appointment_date', $today)
            ->whereIn('status', [
                Appointment::STATUS_SCHEDULED,
                Appointment::STATUS_CHECKED_IN,
                Appointment::STATUS_IN_SERVICE,
            ])
            ->orderBy('appointment_time')
            ->limit(10)
            ->get()
            ->map(fn (Appointment $appointment) => $this->mapUpcoming($appointment))
            ->all();
    }

    private function mapUpcoming(Appointment $appointment): array
    {
        $services = $appointment->services;

        $serviceLabel = $services->count() > 1
            ? 'Multiple'
            : ($services->first()?->serviceVariant?->service?->name ?? 'Service');

        $assignments = $services->flatMap(fn ($service) => $service->therapistAssignments);

        $therapistNames = $assignments->pluck('staff')->filter()
            ->map(fn ($staff) => trim("{$staff->first_name} {$staff->last_name}"))
            ->unique()->values();
        $roomNames = $assignments->pluck('facility')->filter()
            ->pluck('name')->unique()->values();

        $queueStatus = $appointment->queue?->queue_status;
        $status = in_array($queueStatus, ['Waiting', 'Called', 'Serving'], true)
            ? 'queue'
            : match ($appointment->status) {
                Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_SERVICE => 'awaiting',
                Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW => 'cancelled',
                default => 'confirmed',
            };

        return [
            'id' => $appointment->id,
            'time' => substr($appointment->appointment_time, 0, 5),
            'clientName' => trim("{$appointment->client->first_name} {$appointment->client->last_name}"),
            'service' => $serviceLabel,
            'therapistName' => $therapistNames->count() > 1 ? 'Multiple' : ($therapistNames->first() ?? 'Unassigned'),
            'room' => $roomNames->first() ?? '',
            'channel' => $appointment->appointment_type === 'Walk-in' ? 'Walk-in' : 'In-spa',
            'status' => $status,
        ];
    }

    private function staffOnDuty(int $branchId): array
    {
        $busyStaffIds = TherapistAssignment::where('assignment_status', 'In Progress')
            ->whereNotNull('staff_id')
            ->pluck('staff_id')
            ->all();

        return Staff::where('spa_branch_id', $branchId)
            ->whereIn('status', ['active', 'on-leave'])
            ->get()
            ->map(fn (Staff $staff) => [
                'id' => $staff->id,
                'name' => trim("{$staff->first_name} {$staff->last_name}"),
                'role' => $staff->role,
                'status' => $staff->status === 'on-leave'
                    ? 'on_leave'
                    : (in_array($staff->id, $busyStaffIds, true) ? 'busy' : 'available'),
            ])
            ->values()
            ->all();
    }
}
