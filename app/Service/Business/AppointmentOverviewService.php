<?php

namespace App\Service\Business;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\SpaBranch;
use App\Models\User;
use App\Repository\Business\AppointmentListQuery;
use App\Repository\SpaBusinessRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The figures above and beside the owner's Appointments list, counted by the database rather
 * than from every visit sent to the browser: the headline numbers (with the period before, for
 * the change), the day-by-day trend, the staff and services to filter by, the status counts
 * of the list's dropdown, and a branch's visits per day for its calendar.
 *
 * Every count uses AppointmentListQuery, the list's own filters, so the figures and the list
 * always agree.
 */
class AppointmentOverviewService
{
    // The trend shows at most this many days (the last ones of a longer period).
    private const TREND_DAYS = 30;

    public function __construct(private SpaBusinessRepository $businesses) {}

    /**
     * The caller's branches, or just the one asked for (when it is theirs). Null when the
     * account has no business.
     */
    public function branchIds(User $user, ?string $branchUuid = null): ?array
    {
        if (! $this->businesses->findForUser($user)) {
            return null;
        }
        $ids = $this->businesses->branchIdsForUser($user);
        if ($branchUuid) {
            $ids = SpaBranch::whereIn('id', $ids)->where('uuid', $branchUuid)->pluck('id')->all();
        }

        return $ids;
    }

    /** How many visits each of the list's status groups holds, with every other filter applied. */
    public function statusCounts(array $branchIds, array $filters): array
    {
        $byStatus = AppointmentListQuery::apply(Appointment::query(), $filters, withStatus: false)
            ->whereIn('spa_branch_id', $branchIds)
            ->groupBy('appointments.status')
            ->selectRaw('appointments.status as s, count(*) as n')
            ->pluck('n', 's');

        $counts = ['all' => (int) $byStatus->sum()];
        foreach (AppointmentListQuery::LIST_STATUSES as $group) {
            $counts[$group] = (int) collect(AppointmentListQuery::statusesFor($group))->sum(fn ($s) => $byStatus[$s] ?? 0);
        }

        return $counts;
    }

    /**
     * The headline figures for the period and the one just before it (same length), the
     * day-by-day trend (per branch too), and the staff and services seen in the period.
     */
    public function summary(array $branchIds, string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $days = $start->diffInDays($end) + 1;
        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1);

        return [
            'current' => $this->figures($branchIds, $start, $end),
            'previous' => $this->figures($branchIds, $prevStart, $prevEnd),
            'trend' => $this->trend($branchIds, $start, $end),
            'options' => $this->options($branchIds, $start, $end),
        ];
    }

    /** A branch's visits per day (cancelled and no-shows left out), for its month calendar. */
    public function dayCounts(array $branchIds, string $from, string $to): array
    {
        return Appointment::whereIn('spa_branch_id', $branchIds)
            ->whereBetween('appointment_date', [$from, $to])
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value])
            ->groupBy('appointment_date')
            ->selectRaw('appointment_date as d, count(*) as n')
            ->pluck('n', 'd')
            ->mapWithKeys(fn ($n, $date) => [substr((string) $date, 0, 10) => (int) $n])
            ->all();
    }

    // Visits per status, what the kept ones are worth, and how many are in the spa now.
    private function figures(array $branchIds, Carbon $from, Carbon $to): array
    {
        $rows = Appointment::whereIn('spa_branch_id', $branchIds)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('status')
            ->get(['status', DB::raw('count(*) as n'), DB::raw('coalesce(sum(total_amount), 0) as amount')])
            ->keyBy('status');
        $n = fn (AppointmentStatus $s) => (int) ($rows[$s->value]->n ?? 0);
        $lost = [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value];

        return [
            'total' => (int) $rows->sum('n'),
            'scheduled' => $n(AppointmentStatus::Scheduled),
            'completed' => $n(AppointmentStatus::Completed),
            'cancelled' => $n(AppointmentStatus::Cancelled),
            'no_show' => $n(AppointmentStatus::NoShow),
            'in_spa' => $n(AppointmentStatus::CheckedIn) + $n(AppointmentStatus::InService),
            // What the booked visits are worth: all but the cancelled and the ones who never came.
            'value' => round((float) $rows->reject(fn ($r) => in_array($r->status, $lost, true))->sum('amount'), 2),
        ];
    }

    // The last days of the period (at most TREND_DAYS): each day's visits by how they turned
    // out (done, still open, lost), and per branch.
    private function trend(array $branchIds, Carbon $from, Carbon $to): array
    {
        $first = $to->copy()->subDays(min($from->diffInDays($to) + 1, self::TREND_DAYS) - 1);
        $rows = Appointment::query()
            ->join('spa_branches', 'spa_branches.id', '=', 'appointments.spa_branch_id')
            ->whereIn('appointments.spa_branch_id', $branchIds)
            ->whereBetween('appointments.appointment_date', [$first->toDateString(), $to->toDateString()])
            ->groupBy('appointments.appointment_date', 'appointments.status', 'spa_branches.uuid')
            ->get(['appointments.appointment_date as day', 'appointments.status', 'spa_branches.uuid as branch', DB::raw('count(*) as n')]);

        $days = [];
        for ($d = $first->copy(); $d->lte($to); $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'completed' => 0, 'open' => 0, 'lost' => 0, 'by_branch' => []];
        }
        foreach ($rows as $row) {
            $key = substr((string) $row->day, 0, 10);
            if (! isset($days[$key])) {
                continue;
            }
            $n = (int) $row->n;
            $bucket = match ($row->status) {
                AppointmentStatus::Completed->value => 'completed',
                AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value => 'lost',
                default => 'open',
            };
            $days[$key][$bucket] += $n;
            $days[$key]['by_branch'][$row->branch] = ($days[$key]['by_branch'][$row->branch] ?? 0) + $n;
        }

        // by_branch as an object, so a day with none is {} rather than [].
        return array_values(array_map(fn ($d) => array_merge($d, ['by_branch' => (object) $d['by_branch']]), $days));
    }

    // The staff and services the period's visits show, by the names the visits keep, to filter by.
    private function options(array $branchIds, Carbon $from, Carbon $to): array
    {
        $visits = fn ($q) => $q->whereIn('appointments.spa_branch_id', $branchIds)
            ->whereBetween('appointments.appointment_date', [$from->toDateString(), $to->toDateString()]);

        $staff = DB::table('therapist_assignments as ta')
            ->join('appointment_services as s', 's.id', '=', 'ta.appointment_service_id')
            ->join('appointments', 'appointments.id', '=', 's.appointment_id')
            ->leftJoin('staff', 'staff.id', '=', 'ta.staff_id')
            ->tap($visits)
            ->whereNull('appointments.deleted_at')
            ->distinct()
            ->selectRaw("COALESCE(NULLIF(ta.staff_name, ''), TRIM(TRIM(staff.first_name) || ' ' || TRIM(staff.last_name))) as name")
            ->pluck('name')
            ->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();

        // A visit with nobody on it yet can be picked out as "Unassigned".
        $unassigned = Appointment::query()->tap($visits)
            ->whereDoesntHave('services.therapistAssignments', fn ($t) => $t->whereNotNull('staff_id')->orWhereNotNull('staff_name'))
            ->exists();

        $services = DB::table('appointment_services as s')
            ->join('appointments', 'appointments.id', '=', 's.appointment_id')
            ->leftJoin('service_variants as v', 'v.id', '=', 's.service_variant_id')
            ->leftJoin('services as sv', 'sv.id', '=', 'v.service_id')
            ->tap($visits)
            ->whereNull('appointments.deleted_at')
            ->distinct()
            ->selectRaw("COALESCE(NULLIF(s.service_name, ''), sv.name) as name")
            ->pluck('name')
            ->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();

        return [
            'therapists' => $unassigned ? [...$staff->all(), 'Unassigned'] : $staff->all(),
            'services' => $services->all(),
        ];
    }
}
