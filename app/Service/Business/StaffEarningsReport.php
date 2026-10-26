<?php

namespace App\Service\Business;

use App\Models\Staff;
use App\Models\User;
use App\Repository\SpaBusinessRepository;
use App\Support\AttendancePenalty;
use Carbon\Carbon;

// The Earnings page: what every staff member of the caller's branches earned
// between two dates (the current pay period unless asked otherwise) —
// commission and home-service fees, and the late and absent penalties and the
// parts of refunds charged to them (refund policies that charge staff,
// App\Support\RefundCharge) — down to what is left, never below zero. A row adds
// up to that employee's own Earnings tab (StaffEarningsService).
class StaffEarningsReport
{
    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private CommissionCalculator $commissionCalculator,
        private AttendancePenaltyReader $penalties,
        private StaffRefundChargeReader $refundCharges,
    ) {
    }

    public function report(User $user, ?string $from = null, ?string $to = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $policy = $this->commissionCalculator->policy($business);
        $end = $to ? Carbon::parse($to) : now();
        $start = $from ? Carbon::parse($from) : $this->commissionCalculator->periodStart($policy, now());
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) > StaffEarningsService::MAX_DAYS) {
            return response()->json(['message' => 'Pick a range of three months or less.'], 422);
        }

        $branchIds = $this->spaBusinessRepository->branchIdsForUser($user);
        // Everyone who performs services, and anyone else on the roster —
        // a late day costs the same whoever it is.
        $staff = Staff::with('branch')
            ->whereIn('spa_branch_id', $branchIds)
            ->whereIn('status', ['active', 'on-leave'])
            ->orderBy('first_name')
            ->get();
        $ids = $staff->pluck('id')->all();

        $earned = $this->commissionCalculator->summary($business, $ids, $start, $end);
        $penalties = $this->penalties->forStaff($business, $branchIds, $ids, $start, $end);
        $charges = $this->refundCharges->forStaff($business->id, $ids, $start, $end);

        $rows = $staff->map(function (Staff $s) use ($earned, $penalties, $charges) {
            $e = $earned[$s->id];
            $days = $penalties[$s->id] ?? [];
            $late = array_values(array_filter($days, fn ($d) => $d['kind'] === 'late'));
            $absent = array_values(array_filter($days, fn ($d) => $d['kind'] === 'absent'));
            $refunds = $charges[$s->id] ?? [];
            $gross = round($e['commission'] + $e['home_fees'], 2);
            $settled = AttendancePenalty::settle($gross, array_sum(array_column($days, 'amount')) + array_sum(array_column($refunds, 'amount')));

            return [
                'staff_uuid' => $s->uuid,
                'staff_name' => trim("{$s->first_name} {$s->last_name}"),
                'role' => $s->role,
                'branch_uuid' => $s->branch?->uuid,
                'branch_name' => $s->branch?->branch_name,
                'services_done' => $e['services_done'],
                'commission' => $e['commission'],
                'home_fees' => $e['home_fees'],
                'gross' => $gross,
                'late_days' => count($late),
                'late_amount' => round(array_sum(array_column($late, 'amount')), 2),
                'absent_days' => count($absent),
                'absent_amount' => round(array_sum(array_column($absent, 'amount')), 2),
                // Refunds charged to them (part of `penalties`, which is everything taken off).
                'refund_count' => count($refunds),
                'refund_amount' => round(array_sum(array_column($refunds, 'amount')), 2),
                'penalties' => $settled['penalties'],
                'capped' => $settled['capped'],
                'net' => $settled['net'],
            ];
        })
            // Someone with nothing earned or charged has nothing to show.
            ->filter(fn ($r) => $r['role'] === 'therapist' || $r['gross'] > 0 || $r['penalties'] > 0)
            ->map(fn ($r) => $r + ['status' => null])
            ->values();

        // Someone who has since left (switched off or removed) is still owed what
        // they earned: they stay on the list for as long as there is something
        // earned in the range, marked as such.
        $former = Staff::withTrashed()->with('branch')
            ->whereIn('spa_branch_id', $branchIds)
            ->where(fn ($q) => $q->whereNotIn('status', ['active', 'on-leave'])->orWhereNotNull('deleted_at'))
            ->get();
        if ($former->isNotEmpty()) {
            $owed = $this->commissionCalculator->summary($business, $former->pluck('id')->all(), $start, $end);
            $formerCharges = $this->refundCharges->forStaff($business->id, $former->pluck('id')->all(), $start, $end);
            $left = $former->map(function (Staff $s) use ($owed, $formerCharges) {
                $e = $owed[$s->id];
                $gross = round($e['commission'] + $e['home_fees'], 2);
                $refunds = $formerCharges[$s->id] ?? [];
                $settled = AttendancePenalty::settle($gross, array_sum(array_column($refunds, 'amount')));

                return [
                    'staff_uuid' => $s->uuid,
                    'staff_name' => trim("{$s->first_name} {$s->last_name}"),
                    'role' => $s->role,
                    'branch_uuid' => $s->branch?->uuid,
                    'branch_name' => $s->branch?->branch_name,
                    'services_done' => $e['services_done'],
                    'commission' => $e['commission'],
                    'home_fees' => $e['home_fees'],
                    'gross' => $gross,
                    'late_days' => 0,
                    'late_amount' => 0.0,
                    'absent_days' => 0,
                    'absent_amount' => 0.0,
                    'refund_count' => count($refunds),
                    'refund_amount' => round(array_sum(array_column($refunds, 'amount')), 2),
                    'penalties' => $settled['penalties'],
                    'capped' => $settled['capped'],
                    'net' => $settled['net'],
                    'status' => $s->trashed() ? 'removed' : 'inactive',
                ];
            })->filter(fn ($r) => $r['gross'] > 0);
            $rows = $rows->concat($left)->values();
        }

        $sum = fn (string $key) => round($rows->sum($key), 2);

        return ['data' => [
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
            'commission' => $this->commissionCalculator->describe($business),
            'penalty_rules' => $this->rules($business),
            'totals' => [
                'commission' => $sum('commission'),
                'home_fees' => $sum('home_fees'),
                'gross' => $sum('gross'),
                'penalties' => $sum('penalties'),
                'capped' => $sum('capped'),
                'net' => $sum('net'),
            ],
            'rows' => $rows,
        ]];
    }

    // The penalties in force today, for the line under the figures.
    public function rules(\App\Models\SpaBusiness $business): array
    {
        $business->loadMissing('settings');
        $p = $business->settings?->section('staff_policy') ?? \App\Models\SpaBusinessSetting::DEFAULTS['staff_policy'];

        return [
            'late' => ! empty($p['late_penalty_enabled']) ? (float) $p['late_penalty_amount'] : null,
            'late_mode' => ($p['late_penalty_mode'] ?? 'day') === 'hour' ? 'hour' : 'day',
            'absent' => ! empty($p['absent_penalty_enabled']) ? (float) $p['absent_penalty_amount'] : null,
            'tracking' => (bool) ($p['attendance_tracking'] ?? true),
        ];
    }
}
