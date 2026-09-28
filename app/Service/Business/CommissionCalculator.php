<?php

namespace App\Service\Business;

use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// What each therapist has earned in commission, derived on read from their
// completed TherapistAssignments — there is no stored ledger. The rules all
// come from the owner's Settings → Staff Policies:
//   - commission_enabled: off → nobody earns anything (callers hide the UI).
//   - commission_type: 'percentage' of the service line's subtotal, or a
//     'fixed' amount per completed service.
//   - commission_applies_to: 'service', 'package' or 'both' — a line that
//     came out of a package (source_appointment_package_id) counts as package.
//   - commission_release_cycle: daily / weekly (Monday start) / monthly —
//     the "pay period" the running total is shown for.
// A service performed by more than one therapist splits its commission
// equally between everyone who completed it.
//
// Used by the front-desk attendance roster, fill-in suggestions and the
// "lowest earnings first" therapist rotation.
class CommissionCalculator
{
    /** Staff Policies with defaults, or null when commission is turned off. */
    public function policy(?SpaBusiness $business): ?array
    {
        $business?->loadMissing('settings');
        $policy = $business?->settings?->section('staff_policy') ?? SpaBusinessSetting::DEFAULTS['staff_policy'];

        return $policy['commission_enabled'] ? $policy : null;
    }

    /** Start of the current pay period for the owner's release cycle. */
    public function periodStart(?array $policy, ?Carbon $now = null): Carbon
    {
        $now = ($now ?? now())->copy();

        return match ($policy['commission_release_cycle'] ?? 'monthly') {
            'daily' => $now->startOfDay(),
            'weekly' => $now->startOfWeek(Carbon::MONDAY),
            default => $now->startOfMonth(),
        };
    }

    public function periodLabel(?array $policy): string
    {
        return match ($policy['commission_release_cycle'] ?? 'monthly') {
            'daily' => 'today',
            'weekly' => 'this week',
            default => 'this month',
        };
    }

    /**
     * Today's and this pay period's earnings per staff id. Every id asked for
     * comes back (0.0 when they've completed nothing); an empty array when
     * commission is off.
     *
     * @param  int[]  $staffIds
     * @return array<int, array{today: float, period: float}>
     */
    public function earnings(?SpaBusiness $business, array $staffIds, ?Carbon $now = null): array
    {
        $policy = $this->policy($business);
        if (! $policy || ! $staffIds) {
            return [];
        }

        $now = $now ?? now();
        $todayStart = $now->copy()->startOfDay();
        $periodStart = $this->periodStart($policy, $now);
        // One query covering whichever window reaches further back.
        $from = $periodStart->lt($todayStart) ? $periodStart : $todayStart;

        $result = array_fill_keys($staffIds, ['today' => 0.0, 'period' => 0.0]);

        foreach ($this->lines($staffIds, $from, $now) as $line) {
            $amount = $this->amountFor($line, $policy);
            if ($amount <= 0) {
                continue;
            }

            $completedAt = Carbon::parse($line->completed_at);
            if ($completedAt->gte($periodStart)) {
                $result[$line->staff_id]['period'] += $amount;
            }
            if ($completedAt->gte($todayStart)) {
                $result[$line->staff_id]['today'] += $amount;
            }
        }

        return array_map(fn ($r) => ['today' => round($r['today'], 2), 'period' => round($r['period'], 2)], $result);
    }

    /**
     * Earnings per calendar day for one staff member between two dates —
     * the per-staff attendance history.
     *
     * @return array<string, float> keyed by Y-m-d
     */
    public function dailyEarnings(?SpaBusiness $business, int $staffId, Carbon $from, Carbon $to): array
    {
        $policy = $this->policy($business);
        if (! $policy) {
            return [];
        }

        $byDay = [];
        foreach ($this->lines([$staffId], $from->copy()->startOfDay(), $to->copy()->endOfDay()) as $line) {
            $day = Carbon::parse($line->completed_at)->format('Y-m-d');
            $byDay[$day] = round(($byDay[$day] ?? 0) + $this->amountFor($line, $policy), 2);
        }

        return $byDay;
    }

    private function lines(array $staffIds, Carbon $from, Carbon $to)
    {
        return DB::table('therapist_assignments as ta')
            ->join('appointment_services as s', 's.id', '=', 'ta.appointment_service_id')
            ->whereIn('ta.staff_id', $staffIds)
            ->where('ta.assignment_status', 'Completed')
            ->whereBetween('ta.completed_at', [$from, $to])
            ->select([
                'ta.staff_id',
                'ta.completed_at',
                's.subtotal',
                's.unit_price',
                's.quantity',
                's.source_appointment_package_id',
                // How many therapists completed this same service line.
                DB::raw("(select count(*) from therapist_assignments t2 where t2.appointment_service_id = ta.appointment_service_id and t2.assignment_status = 'Completed') as split"),
            ])
            ->get();
    }

    private function amountFor(object $line, array $policy): float
    {
        $isPackage = $line->source_appointment_package_id !== null;
        $appliesTo = $policy['commission_applies_to'] ?? 'service';
        if (($isPackage && $appliesTo === 'service') || (! $isPackage && $appliesTo === 'package')) {
            return 0.0;
        }

        $rate = (float) ($policy['default_commission_rate'] ?? 0);
        $split = max(1, (int) $line->split);

        if (($policy['commission_type'] ?? 'percentage') === 'fixed') {
            return $rate / $split;
        }

        // A package line may carry no price of its own (the package was paid
        // for as a whole) — fall back to its list price.
        $base = (float) $line->subtotal;
        if ($base <= 0) {
            $base = (float) $line->unit_price * max(1, (int) $line->quantity);
        }

        return $base * $rate / 100 / $split;
    }
}
