<?php

namespace App\Service\Business;

use App\Models\StaffRefundCharge;
use Carbon\Carbon;

// The parts of refunds charged to staff (App\Support\RefundCharge) between
// two days, for the Earnings pages — alongside the late and absent days of
// AttendancePenaltyReader, and taken off the same way.
class StaffRefundChargeReader
{
    /**
     * @param  int[]  $staffIds
     * @return array<int, array<int, array{date: string, kind: string, amount: float, billing_number: ?string, policy: ?string}>> keyed by staff id
     */
    public function forStaff(int $businessId, array $staffIds, Carbon $from, Carbon $to): array
    {
        if (! $staffIds || ! StaffRefundCharge::ready()) {
            return [];
        }

        $out = [];
        StaffRefundCharge::where('spa_business_id', $businessId)
            ->whereIn('staff_id', $staffIds)
            ->whereBetween('charged_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('charged_on')->orderBy('id')
            ->get()
            ->each(function (StaffRefundCharge $c) use (&$out) {
                $out[$c->staff_id][] = [
                    'date' => $c->charged_on->toDateString(),
                    'kind' => 'refund',
                    'amount' => (float) $c->amount,
                    'billing_number' => $c->billing_number,
                    'policy' => $c->policy_name,
                ];
            });

        return $out;
    }
}
