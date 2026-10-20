<?php

namespace App\Service\Business;

use App\Models\SpaBusiness;
use App\Models\StaffPenaltyRate;
use App\Support\AttendancePenalty;
use Carbon\Carbon;

// The late and absent days that cost staff something (Staff Policies →
// Penalties), read off the same resolved days as the attendance roster and
// report (AttendanceService::rosterEntries), each at the rate in force that
// day (StaffPenaltyRate). Nothing is charged while attendance tracking is off.
class AttendancePenaltyReader
{
    public function __construct(private AttendanceService $attendance)
    {
    }

    /**
     * @param  int[]  $branchIds
     * @param  int[]|null  $onlyStaffIds  null: everyone in those branches
     * @return array<int, array<int, array{date: string, kind: string, amount: float}>> keyed by staff id
     */
    public function forStaff(SpaBusiness $business, array $branchIds, ?array $onlyStaffIds, Carbon $from, Carbon $to): array
    {
        $business->loadMissing('settings');
        $policy = $business->settings?->section('staff_policy') ?? \App\Models\SpaBusinessSetting::DEFAULTS['staff_policy'];
        $history = StaffPenaltyRate::history($business->id, $policy);

        $anyOn = collect($history)->contains(fn ($r) => ($r['late_enabled'] && $r['late_amount'] > 0) || ($r['absent_enabled'] && $r['absent_amount'] > 0));
        if (! $anyOn || ! ($policy['attendance_tracking'] ?? true) || ! $branchIds) {
            return [];
        }

        // Nothing is decided about a day that hasn't happened yet.
        $end = $to->copy()->min(now());
        if ($from->gt($end)) {
            return [];
        }

        [$staffMembers, $entries] = $this->attendance->rosterEntries($business, $branchIds, $from->format('Y-m-d'), $end->format('Y-m-d'));
        $idByUuid = $staffMembers->pluck('id', 'uuid');

        $out = [];
        foreach ($entries->groupBy('staff_uuid') as $uuid => $days) {
            $id = $idByUuid[$uuid] ?? null;
            if ($id === null || ($onlyStaffIds !== null && ! in_array($id, $onlyStaffIds, true))) {
                continue;
            }
            $penalties = AttendancePenalty::forDays($days->map(fn ($d) => ['date' => $d['date'], 'status' => $d['status'], 'late_minutes' => $d['late_minutes'] ?? null]), $history);
            if ($penalties) {
                $out[$id] = $penalties;
            }
        }

        return $out;
    }
}
