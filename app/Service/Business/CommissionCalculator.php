<?php

namespace App\Service\Business;

use App\Models\AppointmentServiceItem;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Models\Staff;
use App\Support\CommissionShare;
use App\Support\History;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// What each therapist has earned in commission. The amount for a service is
// worked out ONCE, when it is completed, under the Staff Policies in force
// then, and kept on the therapist's assignment (commission_amount — see
// freeze() and App\Support\History): changing the rate later never rewrites
// what was already earned. An assignment with nothing kept (completed before
// that existed) is worked out from today's rules, as it always was.
// The rules come from the owner's Settings → Staff Policies:
//   - commission_enabled: off → nobody earns anything (callers hide the UI).
//   - commission_type: 'percentage' of the service line's subtotal, or a
//     'fixed' amount per completed service.
//   - default_commission_rate: the STANDARD rate — what everyone earns unless
//     they have a custom one of their own (staff.commission_rate, the same
//     kind as the standard; set on the employee by the owner or a manager).
//   - commission_applies_to: 'service', 'package' or 'both' — a line that
//     came out of a package (source_appointment_package_id) counts as package.
//   - commission_release_cycle: daily / weekly (Monday start) / monthly —
//     the "pay period" the running total is shown for.
// A service performed by more than one therapist splits its commission
// equally between everyone who completed it, each at their own rate.
//
// What was earned SHOWS only once the client has paid for the visit — its
// bill is Paid in full (CommissionShare::paidFor). A service that is done but
// not paid for yet, or whose payment was voided or fully refunded, counts for
// nothing here until the bill is paid; the amount kept at completion is
// untouched and appears, dated when the service was done, the moment it is.
// The same goes for the home-service fee below.
//
// On top of commission, a home visit's flat home-service fee goes to the
// therapist(s) who did it, in full (HomeServiceRules) — split equally when
// more than one therapist completed services on that booking, and counted
// even when commission itself is switched off.
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

    /**
     * Keeps what each therapist earned for a service, at the moment it is
     * completed — under the policy in force right now. Called once from
     * AppointmentService::completeService; the one-off backfill
     * (HistoryBackfill) passes $onlyMissing so nothing kept is overwritten.
     *
     * @return int how many assignments it was kept on
     */
    public function freeze(AppointmentServiceItem $line, bool $onlyMissing = false): int
    {
        if (! History::ready()) {
            return 0;
        }

        $done = $line->therapistAssignments()->where('assignment_status', 'Completed')->get();
        if ($done->isEmpty()) {
            return 0;
        }
        $policy = $this->policy($line->appointment?->branch?->business);

        // Each at their own rate: a custom one where they have it.
        $custom = Staff::hasCustomCommission();
        $ownRates = $custom
            ? Staff::whereIn('id', $done->pluck('staff_id')->filter()->all())
                ->where('role', \App\Support\StaffSpecialty::ROLE)
                ->whereNotNull('commission_rate')
                ->pluck('commission_rate', 'id')
            : collect();

        $kept = 0;
        foreach ($done->whereNotNull('staff_id') as $assignment) {
            if ($onlyMissing && $assignment->commission_amount !== null) {
                continue;
            }
            $own = $ownRates->has($assignment->staff_id) ? (float) $ownRates[$assignment->staff_id] : null;
            $amount = round(CommissionShare::amount(
                $policy,
                (float) $line->subtotal,
                (float) $line->unit_price * max(1, (int) $line->quantity),
                $line->source_appointment_package_id !== null,
                $done->count(),
                $own,
            ), 2);

            $assignment->update(['commission_amount' => $amount] + ($custom && $policy ? [
                'commission_rate' => CommissionShare::rate($policy, $own),
                'commission_type' => $policy['commission_type'] ?? 'percentage',
            ] : []));
            $kept++;
        }

        return $kept;
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
     * Today's, this week's (from Monday) and this pay period's earnings per
     * staff id. Every id asked for comes back (0.0 when they've completed
     * nothing); an empty array when commission is off.
     *
     * @param  int[]  $staffIds
     * @return array<int, array{today: float, week: float, period: float}>
     */
    public function earnings(?SpaBusiness $business, array $staffIds, ?Carbon $now = null): array
    {
        $policy = $this->policy($business);
        if (! $staffIds) {
            return [];
        }

        $now = $now ?? now();
        $todayStart = $now->copy()->startOfDay();
        $periodStart = $this->periodStart($policy, $now);
        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY);
        // One query covering whichever window reaches furthest back.
        $from = $periodStart->copy()->min($todayStart)->min($weekStart);

        $homeFees = $this->homeFeeLines($staffIds, $from, $now)->filter(fn ($line) => $this->paid($line));
        // Commission off and no home visits: nothing to show (callers hide the UI).
        if (! $policy && $homeFees->isEmpty()) {
            return [];
        }

        $result = array_fill_keys($staffIds, ['today' => 0.0, 'week' => 0.0, 'period' => 0.0]);

        $credit = function (int $staffId, float $amount, string $completedAt) use (&$result, $periodStart, $todayStart, $weekStart) {
            if ($amount <= 0) {
                return;
            }
            $at = Carbon::parse($completedAt);
            if ($at->gte($periodStart)) {
                $result[$staffId]['period'] += $amount;
            }
            if ($at->gte($weekStart)) {
                $result[$staffId]['week'] += $amount;
            }
            if ($at->gte($todayStart)) {
                $result[$staffId]['today'] += $amount;
            }
        };

        if ($policy) {
            foreach ($this->lines($staffIds, $from, $now) as $line) {
                // Nothing shows until the client has paid for the visit.
                if (! $this->paid($line)) {
                    continue;
                }
                $credit((int) $line->staff_id, $this->amountFor($line, $policy), $line->completed_at);
            }
        }

        foreach ($homeFees as $line) {
            $credit((int) $line->staff_id, $this->homeFeeShare($line), $line->completed_at);
        }

        return array_map(fn ($r) => ['today' => round($r['today'], 2), 'week' => round($r['week'], 2), 'period' => round($r['period'], 2)], $result);
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
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        $byDay = [];
        $add = function (string $completedAt, float $amount) use (&$byDay) {
            if ($amount <= 0) {
                return;
            }
            $day = Carbon::parse($completedAt)->format('Y-m-d');
            $byDay[$day] = round(($byDay[$day] ?? 0) + $amount, 2);
        };

        if ($policy) {
            foreach ($this->lines([$staffId], $start, $end) as $line) {
                if ($this->paid($line)) {
                    $add($line->completed_at, $this->amountFor($line, $policy));
                }
            }
        }

        foreach ($this->homeFeeLines([$staffId], $start, $end) as $line) {
            if ($this->paid($line)) {
                $add($line->completed_at, $this->homeFeeShare($line));
            }
        }

        return $byDay;
    }

    /**
     * Every earning of one staff member between two dates, newest first: one
     * line per completed service that earned commission, and one per home
     * visit whose fee went to them. The same rules as earnings() and
     * dailyEarnings(), so the lines add up to those figures.
     *
     * @return array{enabled: bool, total: float, services_done: int, lines: array<int, array<string, mixed>>}
     */
    public function ledger(?SpaBusiness $business, int $staffId, Carbon $from, Carbon $to): array
    {
        $policy = $this->policy($business);
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $name = fn (object $line) => trim(($line->client_first_name ?? '') . ' ' . ($line->client_last_name ?? '')) ?: null;

        $serviceLines = $this->lines([$staffId], $start, $end, detailed: true);
        $lines = [];

        if ($policy) {
            foreach ($serviceLines as $line) {
                // Not paid for yet: done (it counts in services_done below),
                // but nothing earned to show.
                if (! $this->paid($line)) {
                    continue;
                }
                $amount = round($this->amountFor($line, $policy), 2);
                if ($amount <= 0) {
                    continue;
                }
                $base = (float) $line->subtotal;
                // The kind it was worked out as: what was kept with the
                // amount, else today's.
                $type = $line->kept_type ?? ($policy['commission_type'] ?? 'percentage');
                $lines[] = [
                    'at' => Carbon::parse($line->completed_at)->toIso8601String(),
                    'date' => Carbon::parse($line->completed_at)->format('Y-m-d'),
                    'kind' => $line->source_appointment_package_id !== null ? 'package' : 'service',
                    'service' => $line->service_name,
                    'client' => $name($line),
                    'appointment_uuid' => $line->appointment_uuid,
                    'appointment_number' => $line->appointment_number,
                    // What the amount was worked out from (the service's
                    // price); null for a fixed amount per service.
                    'base' => $type === 'fixed'
                        ? null
                        : round($base > 0 ? $base : (float) $line->unit_price * max(1, (int) $line->quantity), 2),
                    // The rate it was worked out at — null for one completed
                    // before rates were kept.
                    'rate' => $line->kept_rate !== null ? (float) $line->kept_rate : null,
                    'rate_type' => $line->kept_rate !== null ? $type : null,
                    // How many therapists it was shared between.
                    'split' => max(1, (int) $line->split),
                    'amount' => $amount,
                ];
            }
        }

        foreach ($this->homeFeeLines([$staffId], $start, $end, detailed: true) as $line) {
            $amount = $this->paid($line) ? $this->homeFeeShare($line) : 0.0;
            if ($amount <= 0) {
                continue;
            }
            $lines[] = [
                'at' => Carbon::parse($line->completed_at)->toIso8601String(),
                'date' => Carbon::parse($line->completed_at)->format('Y-m-d'),
                'kind' => 'home_fee',
                'service' => 'Home service fee',
                'client' => $name($line),
                'appointment_uuid' => $line->appointment_uuid,
                'appointment_number' => $line->appointment_number,
                'base' => round((float) $line->home_service_fee, 2),
                'rate' => null,
                'rate_type' => null,
                'split' => max(1, (int) $line->split),
                'amount' => $amount,
            ];
        }

        usort($lines, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return [
            'enabled' => $policy !== null,
            'total' => round(array_sum(array_column($lines, 'amount')), 2),
            // Services they completed in the range, earning or not.
            'services_done' => $serviceLines->count(),
            'lines' => $lines,
        ];
    }

    /**
     * The commission rule in force, for showing next to the figures: the
     * standard rate, and — for one staff member — the rate that applies to
     * them and whether it is a custom one.
     */
    public function describe(?SpaBusiness $business, ?Staff $staff = null): array
    {
        $policy = $this->policy($business);
        $own = $policy ? $staff?->ownCommissionRate() : null;

        return [
            'enabled' => $policy !== null,
            'type' => $policy['commission_type'] ?? null,
            'rate' => $policy ? CommissionShare::rate($policy, $own) : null,
            'standard_rate' => $policy ? CommissionShare::rate($policy) : null,
            'custom' => $own !== null,
            'applies_to' => $policy['commission_applies_to'] ?? null,
            'period_label' => $this->periodLabel($policy),
        ];
    }

    // The visit's bill status, for CommissionShare::paidFor — null when it has
    // no bill yet. A visit has one bill (made at checkout).
    private const BILL_STATUS = "(select b.status from billings b where b.appointment_id = %s and b.deleted_at is null order by b.id desc limit 1) as bill_status";

    /** Whether the visit this line belongs to has been paid for. */
    private function paid(object $line): bool
    {
        return CommissionShare::paidFor($line->bill_status ?? null);
    }

    // One row per (therapist, home visit) they completed a service on in the
    // window, credited at the moment they finished their last service on it.
    // `split` is how many therapists completed services on that booking.
    // $detailed adds whose visit it was (ledger()).
    private function homeFeeLines(array $staffIds, Carbon $from, Carbon $to, bool $detailed = false)
    {
        return DB::table('therapist_assignments as ta')
            ->join('appointment_services as s', 's.id', '=', 'ta.appointment_service_id')
            ->join('appointments as a', 'a.id', '=', 's.appointment_id')
            ->whereIn('ta.staff_id', $staffIds)
            ->where('ta.assignment_status', 'Completed')
            ->where('a.home_service_fee', '>', 0)
            ->groupBy('ta.staff_id', 'a.id', 'a.home_service_fee')
            ->havingRaw('max(ta.completed_at) between ? and ?', [$from, $to])
            ->when($detailed, fn ($q) => $q
                ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
                ->groupBy('a.uuid', 'a.appointment_number', 'c.first_name', 'c.last_name')
                ->addSelect(['a.uuid as appointment_uuid', 'a.appointment_number', 'c.first_name as client_first_name', 'c.last_name as client_last_name']))
            ->addSelect([
                'ta.staff_id',
                'a.home_service_fee',
                DB::raw(sprintf(self::BILL_STATUS, 'a.id')),
                DB::raw('max(ta.completed_at) as completed_at'),
                DB::raw("(select count(distinct t2.staff_id) from therapist_assignments t2 join appointment_services s2 on s2.id = t2.appointment_service_id where s2.appointment_id = a.id and t2.assignment_status = 'Completed' and t2.staff_id is not null) as split"),
            ])
            ->get();
    }

    private function homeFeeShare(object $line): float
    {
        return round((float) $line->home_service_fee / max(1, (int) $line->split), 2);
    }

    // $detailed adds the service's name and whose visit it was (ledger()).
    private function lines(array $staffIds, Carbon $from, Carbon $to, bool $detailed = false)
    {
        return DB::table('therapist_assignments as ta')
            ->join('appointment_services as s', 's.id', '=', 'ta.appointment_service_id')
            ->whereIn('ta.staff_id', $staffIds)
            ->where('ta.assignment_status', 'Completed')
            ->whereBetween('ta.completed_at', [$from, $to])
            ->when($detailed, fn ($q) => $q
                ->join('appointments as a', 'a.id', '=', 's.appointment_id')
                ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
                ->leftJoin('service_variants as v', 'v.id', '=', 's.service_variant_id')
                ->leftJoin('services as sv', 'sv.id', '=', 'v.service_id')
                ->addSelect(['a.uuid as appointment_uuid', 'a.appointment_number', 'c.first_name as client_first_name', 'c.last_name as client_last_name', DB::raw((\App\Support\History::ready() ? 'coalesce(s.service_name, sv.name)' : 'sv.name') . ' as service_name')]))
            ->addSelect([
                'ta.staff_id',
                'ta.completed_at',
                's.subtotal',
                's.unit_price',
                's.quantity',
                's.source_appointment_package_id',
                DB::raw(sprintf(self::BILL_STATUS, 's.appointment_id')),
                // What was kept when it was completed (null: nothing kept).
                DB::raw((History::ready() ? 'ta.commission_amount' : 'null') . ' as commission_amount'),
                // The rate kept with it, and the person's own rate today (for
                // a service with nothing kept).
                DB::raw((Staff::hasCustomCommission() ? 'ta.commission_rate' : 'null') . ' as kept_rate'),
                DB::raw((Staff::hasCustomCommission() ? 'ta.commission_type' : 'null') . ' as kept_type'),
                DB::raw((Staff::hasCustomCommission()
                    ? "(select st.commission_rate from staff st where st.id = ta.staff_id and st.role = 'therapist')"
                    : 'null') . ' as own_rate'),
                // How many therapists completed this same service line.
                DB::raw("(select count(*) from therapist_assignments t2 where t2.appointment_service_id = ta.appointment_service_id and t2.assignment_status = 'Completed') as split"),
            ])
            ->get();
    }

    private function amountFor(object $line, array $policy): float
    {
        // What was earned when it was completed stands, whatever the rules
        // say today.
        if (($line->commission_amount ?? null) !== null) {
            return (float) $line->commission_amount;
        }

        // A package line may carry no price of its own (the package was paid
        // for as a whole) — CommissionShare falls back to its list price.
        return CommissionShare::amount(
            $policy,
            (float) $line->subtotal,
            (float) $line->unit_price * max(1, (int) $line->quantity),
            $line->source_appointment_package_id !== null,
            (int) $line->split,
            ($line->own_rate ?? null) !== null ? (float) $line->own_rate : null,
        );
    }
}
