<?php

namespace App\Service\Business;

use App\Models\User;
use App\Repository\Business\StaffRepository;
use App\Repository\SpaBusinessRepository;
use Carbon\Carbon;

// What one staff member earned between two dates, line by line — the
// Earnings tab of the owner's and manager's employee page. The figures are
// CommissionCalculator's (nothing is stored), so they agree with the front
// desk's attendance roster and history. Read-only; a manager reaches only
// their own branch's staff.
class StaffEarningsService
{
    // The longest range one request answers.
    public const MAX_DAYS = 92;

    public function __construct(
        private SpaBusinessRepository $spaBusinessRepository,
        private StaffRepository $staffRepository,
        private CommissionCalculator $commissionCalculator,
        private AttendancePenaltyReader $penalties,
        private StaffEarningsReport $report,
    ) {
    }

    // Defaults to this month so far.
    public function forStaff(User $user, string $staffUuid, ?string $from = null, ?string $to = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $staff = $this->staffRepository->findByUuidForBranches($staffUuid, $this->spaBusinessRepository->branchIdsForUser($user));

        $end = $to ? Carbon::parse($to) : now();
        $start = $from ? Carbon::parse($from) : $end->copy()->startOfMonth();
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) > self::MAX_DAYS) {
            return response()->json(['message' => 'Pick a range of three months or less.'], 422);
        }

        $ledger = $this->commissionCalculator->ledger($business, $staff->id, $start, $end);
        // Late and absent days that cost them (Staff Policies → Penalties),
        // and what is left — never below zero (AttendancePenalty::settle).
        $penalties = $this->penalties->forStaff($business, [$staff->spa_branch_id], [$staff->id], $start, $end)[$staff->id] ?? [];
        $settled = \App\Support\AttendancePenalty::settle($ledger['total'], array_sum(array_column($penalties, 'amount')));

        return ['data' => [
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
            'commission' => $this->commissionCalculator->describe($business, $staff),
            'penalty_rules' => $this->report->rules($business),
            'total' => $ledger['total'],
            'penalties' => $penalties,
            'penalty_total' => $settled['penalties'],
            'capped' => $settled['capped'],
            'net' => $settled['net'],
            'services_done' => $ledger['services_done'],
            'lines' => $ledger['lines'],
        ]];
    }
}
