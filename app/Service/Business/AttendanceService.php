<?php

namespace App\Service\Business;

use App\Http\Resources\AttendanceDetailResource;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\Staff;
use App\Models\StaffTransferRequest;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\Business\AttendanceRepository;
use App\Repository\Business\StaffRepository;
use App\Repository\SpaBusinessRepository;
use App\Service\Business\StaffNotifier;
use App\Support\AttendanceNotice;
use App\Support\CheckOutRules;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

class AttendanceService
{
    private AttendanceRepository $attendanceRepository;
    private StaffRepository $staffRepository;
    private SpaBusinessRepository $spaBusinessRepository;
    private AttendanceStatusCalculator $statusCalculator;
    private AuditLogRepository $auditLogRepository;
    private ServingNow $servingNow;
    private StaffNotifier $staffNotifier;

    private const AUDIT_FIELDS = ['status', 'check_in_at', 'check_out_at', 'remarks'];

    public function __construct(
        AttendanceRepository $attendanceRepository,
        StaffRepository $staffRepository,
        SpaBusinessRepository $spaBusinessRepository,
        AttendanceStatusCalculator $statusCalculator,
        AuditLogRepository $auditLogRepository,
        ServingNow $servingNow,
        StaffNotifier $staffNotifier,
    ) {
        $this->attendanceRepository = $attendanceRepository;
        $this->staffRepository = $staffRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->statusCalculator = $statusCalculator;
        $this->auditLogRepository = $auditLogRepository;
        $this->servingNow = $servingNow;
        $this->staffNotifier = $staffNotifier;
    }

    // A therapist performing a service right now stays checked in
    // (CheckOutRules): today's record can't be given a check-out time or be
    // cleared until that service is completed. Other days are untouched.
    private function servingProblem(Staff $staff, string $date): ?string
    {
        if (Carbon::parse($date)->format('Y-m-d') !== now()->format('Y-m-d')) {
            return null;
        }

        return CheckOutRules::problem(trim("{$staff->first_name} {$staff->last_name}") ?: 'This staff member', $this->servingNow->for($staff->id));
    }

    // Same scoping as StaffService::branchIds — owner's branch ids cover the
    // whole business; manager's cover only their own staff record's branch.
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchIdsForUser($user);
    }

    public function listForDate(User $user, string $date)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $roster = $this->attendanceRepository->rosterForDate($this->branchIds($user), $date);

        $data = $roster->map(function ($staff) {
            // At most one row per (staff, date) thanks to
            // attendances_staff_date_unique — a null here means this staff
            // member hasn't been marked for this date yet, distinct from an
            // explicit "Absent" status.
            $attendance = $staff->attendance->first();

            return [
                'staff_uuid' => $staff->uuid,
                'attendance_uuid' => $attendance?->uuid,
                'employee_number' => $staff->employee_number,
                'first_name' => $staff->first_name,
                'last_name' => $staff->last_name,
                'role' => $staff->role,
                'branch_uuid' => $staff->branch?->uuid,
                'branch_name' => $staff->branch?->branch_name,
                'status' => $attendance?->status,
                'check_in_at' => $attendance?->check_in_at?->format('H:i'),
                'check_out_at' => $attendance?->check_out_at?->format('H:i'),
                'remarks' => $attendance?->remarks,
            ];
        })->values();

        return ['date' => $date, 'data' => $data];
    }

    public function markAttendance(User $user, array $payload, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // 404s if this uuid isn't (or isn't a staff member of) one of this
        // user's own accessible branches — same guard StaffService uses
        // before creating/updating a staff record.
        $staff = $this->staffRepository->findByUuidForBranches($payload['staff_uuid'], $this->branchIds($user));

        $date = $payload['attendance_date'];

        if (isset($payload['check_out_at']) && ($serving = $this->servingProblem($staff, $date))) {
            return response()->json(['message' => $serving], 422);
        }

        $existing = Attendance::where('staff_id', $staff->id)->where('attendance_date', $date)->first();
        $oldValues = $existing?->only(self::AUDIT_FIELDS);

        $model = $this->attendanceRepository->upsert($staff->id, $date, [
            'status' => $payload['status'],
            'check_in_at' => isset($payload['check_in_at']) ? Carbon::parse($date . ' ' . $payload['check_in_at']) : null,
            'check_out_at' => isset($payload['check_out_at']) ? Carbon::parse($date . ' ' . $payload['check_out_at']) : null,
            'remarks' => $payload['remarks'] ?? null,
            'created_by' => $existing?->created_by ?? $user->id,
            'updated_by' => $user->id,
        ]);

        $this->logAttendanceChange($user, $model, $existing ? 'Update' : 'Create', $oldValues, $request);
        $this->tellManagers($user, $staff, $date, $existing ? $oldValues : null, $model->only(self::AUDIT_FIELDS));

        return new AttendanceResource($model);
    }

    // Fills in "Present" only for staff scheduled to work that day who have no
    // attendance row yet for this
    // date — never overwrites an existing Late/Absent/etc. entry, so a
    // manager who already marked exceptions can safely bulk-fill the rest.
    public function bulkMarkPresent(User $user, string $date, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // Marking everyone at once is for one branch: the owner picks it first.
        $branchIds = $this->branchIds($user);
        if (count($branchIds) > 1) {
            return response()->json(['message' => 'Pick a branch first, then mark everyone there present.'], 422);
        }

        // Only people who were due to work: a day off, or no schedule at all
        // for the day, is not someone to mark present.
        $roster = $this->attendanceRepository->rosterForRange($branchIds, $date, $date);
        $today = Carbon::today()->format('Y-m-d');
        $branchSchedules = BranchSchedule::whereIn('spa_branch_id', $branchIds)->where('day_of_week', Carbon::parse($date)->format('l'))->get()->keyBy('spa_branch_id');

        // A whole branch is many records, and each one saved on its own is
        // several round trips to the database. So the rows are worked out here
        // (the roster, the branch's hours and the business's settings are each
        // read once) and written together in one go.
        $now = Carbon::now();
        $rows = [];
        $marked = [];

        foreach ($roster as $staff) {
            if (! $staff->attendance->isEmpty() || ! $this->statusCalculator->hasWorkingScheduleForDate($staff->schedules, $date)) {
                continue;
            }

            // Recorded as the schedule had it, the same as "Was in": in at the
            // shift's start, out at its end once the day is over. Today's shift
            // is still running, and a day to come has no times yet.
            $shift = $this->statusCalculator->resolve($staff, null, $date, $staff->schedules, $branchSchedules->get($staff->spa_branch_id));
            $start = $shift['scheduled_start'];
            $end = $shift['scheduled_end'];
            $times = [];
            if ($start && $date <= $today) {
                $in = Carbon::parse("{$date} {$start}");
                // Today: nobody can have timed in at a time that has not come yet,
                // so a shift that starts later is in as of now.
                if ($date === $today && $in->gt(Carbon::now())) {
                    $in = Carbon::now()->startOfMinute();
                }
                $times['check_in_at'] = $in;
                if ($end && $end > $start && $date < $today) {
                    $times['check_out_at'] = Carbon::parse("{$date} {$end}");
                }
            }

            $model = new Attendance([
                'staff_id' => $staff->id,
                'attendance_date' => $date,
                'status' => 'Present',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ] + $times);
            $model->uuid = $model->newUniqueId();
            if (StaffTransferRequest::ready()) {
                $model->spa_branch_id = $staff->spa_branch_id;
            }
            $this->statusCalculator->keepWith($model, $staff, $branchSchedules->get($staff->spa_branch_id), $business);
            $model->setCreatedAt($now);
            $model->setUpdatedAt($now);

            $rows[] = $model->getAttributes();
            $marked[] = trim("{$staff->first_name} {$staff->last_name}");
        }

        if ($rows) {
            // One insert takes the same columns for every row.
            $columns = array_keys(array_merge(...$rows));
            $rows = array_map(fn ($row) => array_merge(array_fill_keys($columns, null), $row), $rows);

            // Anyone recorded in the meantime is left as they are.
            Attendance::insertOrIgnore($rows);

            $this->auditLogRepository->record(
                $user->id, 'attendances', null, 'Create', null,
                ['date' => $date, 'status' => 'Present', 'marked_all_present' => true, 'staff' => $marked],
                $request
            );
        }

        return $this->listForDate($user, $date);
    }

    // Owner-facing read-only analytics report — a date range's worth of raw
    // attendance rows, plus a per-branch active-staff count for the
    // frontend's attendance-rate denominators. All trend/breakdown/top-issues
    // aggregation happens client-side from this flat data, not here — see
    // useOwnerAttendanceAnalyticsPage.ts.
    public function report(User $user, string $dateFrom, string $dateTo)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // A year at most: the report is worked out day by day.
        $earliest = Carbon::parse($dateTo)->subDays(365)->format('Y-m-d');
        if ($dateFrom < $earliest) {
            $dateFrom = $earliest;
        }

        $branchIds = $this->branchIds($user);
        [$staffMembers, $entries] = $this->rosterEntries($business, $branchIds, $dateFrom, $dateTo);

        $branches = $staffMembers
            ->groupBy('spa_branch_id')
            ->map(fn ($staff) => [
                'branch_uuid' => $staff->first()->branch?->uuid,
                'branch_name' => $staff->first()->branch?->branch_name,
                'active_staff_count' => $staff->count(),
            ])
            ->values();

        // What happened, day by day, worked out the same way as the manager's
        // roster (rosterReport below): someone scheduled who never checked in
        // is Absent, a check-in past the grace time is Late, no check-out by
        // the end of the day is Incomplete. Days on which nothing was
        // expected of someone (a day off, no shift, before they were added)
        // have no status and are left out.
        $data = $entries
            ->filter(fn ($entry) => $entry['status'] !== null)
            ->map(fn ($entry) => [
                'staff_uuid' => $entry['staff_uuid'],
                'staff_name' => $entry['staff_name'],
                'role' => $entry['position'],
                'branch_uuid' => $entry['branch_uuid'],
                'branch_name' => $entry['branch_name'],
                'attendance_date' => $entry['date'],
                'status' => $entry['status'],
                'check_in_at' => $entry['check_in_at'],
                'check_out_at' => $entry['check_out_at'],
                'late_minutes' => $entry['late_minutes'],
                'work_minutes' => $entry['work_minutes'],
                'overtime_minutes' => $entry['overtime_minutes'],
                'scheduled_start' => $entry['scheduled_start'],
                'scheduled_end' => $entry['scheduled_end'],
            ]);

        // Records of people who have since been made inactive: they are not
        // on the roster any more, but what they did in the range still
        // happened. Shown as recorded.
        $onRoster = $staffMembers->pluck('id')->all();
        $former = $this->attendanceRepository->rangeForBranches($branchIds, $dateFrom, $dateTo)
            ->reject(fn ($a) => in_array($a->staff_id, $onRoster, true))
            ->map(fn ($a) => [
                'staff_uuid' => $a->staff?->uuid,
                'staff_name' => trim("{$a->staff?->first_name} {$a->staff?->last_name}"),
                'role' => $a->staff?->role,
                // Where it was recorded, not where they are now.
                'branch_uuid' => $a->ownBranch()?->uuid,
                'branch_name' => $a->ownBranch()?->branch_name,
                'attendance_date' => $a->attendance_date->format('Y-m-d'),
                'status' => $a->status,
                'check_in_at' => $a->check_in_at?->format('H:i'),
                'check_out_at' => $a->check_out_at?->format('H:i'),
                'late_minutes' => null,
                'work_minutes' => null,
                'overtime_minutes' => null,
                'scheduled_start' => null,
                'scheduled_end' => null,
            ])
            ->filter(fn ($row) => $row['status'] !== null);

        // Today, branch by branch — whatever range is on screen. Read off the
        // range when it includes today, worked out on its own when it doesn't.
        $today = now()->format('Y-m-d');
        $todayEntries = ($today >= $dateFrom && $today <= $dateTo)
            ? $entries->where('date', $today)
            : $this->rosterEntries($business, $branchIds, $today, $today)[1];

        // The same length of time just before the range, for "up 4 points on
        // the previous 30 days".
        $days = Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo)) + 1;
        $previousTo = Carbon::parse($dateFrom)->subDay();
        $previousFrom = $previousTo->copy()->subDays((int) $days - 1);
        [, $previousEntries] = $this->rosterEntries($business, $branchIds, $previousFrom->format('Y-m-d'), $previousTo->format('Y-m-d'));

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'total_staff' => $staffMembers->count(),
            'branches' => $branches,
            'today' => ['date' => $today, 'branches' => $this->todayByBranch($todayEntries)],
            'previous' => [
                'date_from' => $previousFrom->format('Y-m-d'),
                'date_to' => $previousTo->format('Y-m-d'),
                'branches' => $this->totalsByBranch($previousEntries),
            ],
            'data' => $data->concat($former)->sortByDesc('attendance_date')->values(),
        ];
    }

    // Statuses that take someone out of the count of people due to work a
    // day: excused (leave, a holiday) or never scheduled (a fill-in). The web
    // app counts the same way (utils/attendanceMath.ts) — keep the two in step.
    private const NOT_SCHEDULED = ['On Leave', 'Holiday', 'Fill In'];

    /**
     * Where each branch stands right now: how many are due in today, and how
     * many of them are in, done, late, still to arrive, or absent.
     */
    private function todayByBranch($entries): array
    {
        return collect($entries)
            ->groupBy('branch_uuid')
            ->map(function ($rows) {
                $due = $rows->filter(fn ($e) => $e['scheduled_start'] !== null && ! $e['is_day_off'] && ! in_array($e['status'], self::NOT_SCHEDULED, true));

                return [
                    'branch_uuid' => $rows->first()['branch_uuid'],
                    'branch_name' => $rows->first()['branch_name'],
                    'scheduled' => $due->count(),
                    // Checked in, whether or not they have left again.
                    'checked_in' => $due->filter(fn ($e) => $e['check_in_at'] !== null)->count(),
                    'on_shift' => $rows->filter(fn ($e) => $e['check_in_at'] !== null && $e['check_out_at'] === null && $e['status'] !== 'Incomplete')->count(),
                    'late' => $rows->where('status', 'Late')->count(),
                    'not_in_yet' => $due->filter(fn ($e) => $e['check_in_at'] === null && $e['status'] === null)->count(),
                    'absent' => $rows->where('status', 'Absent')->count(),
                    'on_leave' => $rows->filter(fn ($e) => in_array($e['status'], ['On Leave', 'Holiday'], true))->count(),
                ];
            })
            ->values()
            ->all();
    }

    /** Scheduled shifts and how they went, per branch, for a set of roster entries. */
    private function totalsByBranch($entries): array
    {
        return collect($entries)
            ->filter(fn ($e) => $e['status'] !== null && ! in_array($e['status'], self::NOT_SCHEDULED, true))
            ->groupBy('branch_uuid')
            ->map(fn ($rows) => [
                'branch_uuid' => $rows->first()['branch_uuid'],
                'scheduled' => $rows->count(),
                'came_in' => $rows->where('status', '!=', 'Absent')->count(),
                'late' => $rows->where('status', 'Late')->count(),
                'absent' => $rows->where('status', 'Absent')->count(),
            ])
            ->values()
            ->all();
    }

    // Manager-facing monitoring report — unlike report() above, this
    // cross-produces active staff x every date in the range so a staff
    // member with zero rows on a date still yields a synthetic
    // Absent/Incomplete/unmarked entry (needed for "Missing Check In" /
    // "forgot to check out" views), with status/late/work-hours resolved
    // through AttendanceStatusCalculator so every consumer (summary cards,
    // chart, table, issues panel, CSV export) agrees on the same numbers.
    public function rosterReport(User $user, string $dateFrom, string $dateTo)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        [$staffMembers, $entries] = $this->rosterEntries($business, $this->branchIds($user), $dateFrom, $dateTo);

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'total_staff' => $staffMembers->count(),
            'data' => $entries->values(),
        ];
    }

    /**
     * Every active staff member of these branches on every day of the range,
     * with what AttendanceStatusCalculator makes of it. The one place the
     * manager's roster and the owner's report are built from — and the late
     * and absent days the Earnings penalties count (AttendancePenaltyReader).
     *
     * @return array{0: \Illuminate\Support\Collection<int, Staff>, 1: \Illuminate\Support\Collection<int, array>}
     */
    public function rosterEntries(\App\Models\SpaBusiness $business, array $branchIds, string $dateFrom, string $dateTo): array
    {
        // Only read below (the late-grace and overtime policies), so the
        // cached copy of the settings does instead of another query.
        if (! $business->relationLoaded('settings')) {
            $business->setRelation('settings', \App\Support\AppCache::businessSettings($business->id));
        }

        $staffMembers = $this->attendanceRepository->rosterForRange($branchIds, $dateFrom, $dateTo);

        // Prefetch every involved branch's weekly hours once, grouped by
        // branch, rather than querying per staff x date.
        $branchSchedulesByBranch = BranchSchedule::whereIn('spa_branch_id', $branchIds)->get()->groupBy('spa_branch_id');
        $grace = AttendanceStatusCalculator::graceMinutesFor($business);
        $overtime = AttendanceStatusCalculator::overtimeAllowedFor($business);

        $entries = collect();

        foreach ($staffMembers as $staff) {
            $attendanceByDate = $staff->attendance->keyBy(fn ($a) => $a->attendance_date->format('Y-m-d'));
            $branchDaySchedules = $branchSchedulesByBranch->get($staff->spa_branch_id, collect());

            foreach (CarbonPeriod::create($dateFrom, $dateTo) as $day) {
                $date = $day->format('Y-m-d');
                $attendance = $attendanceByDate->get($date);
                $branchSchedule = $branchDaySchedules->firstWhere('day_of_week', $day->format('l'));

                $resolved = $this->statusCalculator->resolve($staff, $attendance, $date, $staff->schedules, $branchSchedule, graceMinutes: $grace, allowOvertime: $overtime);

                $entries->push([
                    'attendance_uuid' => $attendance?->uuid,
                    'staff_uuid' => $staff->uuid,
                    'staff_name' => trim("{$staff->first_name} {$staff->last_name}"),
                    'position' => $staff->role,
                    'branch_uuid' => $staff->branch?->uuid,
                    'branch_name' => $staff->branch?->branch_name,
                    'date' => $date,
                    'scheduled_start' => $resolved['scheduled_start'],
                    'scheduled_end' => $resolved['scheduled_end'],
                    'check_in_at' => $attendance?->check_in_at?->format('H:i'),
                    'check_out_at' => $attendance?->check_out_at?->format('H:i'),
                    'work_minutes' => $resolved['work_minutes'],
                    'overtime_minutes' => $resolved['overtime_minutes'],
                    'status' => $resolved['status'],
                    'late_minutes' => $resolved['late_minutes'],
                    'is_day_off' => $resolved['is_day_off'],
                    'missing_check_in' => $resolved['missing_check_in'],
                    'missing_check_out' => $resolved['missing_check_out'],
                    'remarks' => $attendance?->remarks,
                ]);
            }
        }

        return [$staffMembers, $entries];
    }

    public function showDetail(User $user, string $uuid)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $attendance = $this->attendanceRepository->findByUuidForBranchesWithDetail($uuid, $this->branchIds($user));
        $staff = $attendance->staff;

        $branchSchedule = BranchSchedule::where('spa_branch_id', $staff->spa_branch_id)
            ->where('day_of_week', $attendance->attendance_date->format('l'))
            ->first();

        $resolved = $this->statusCalculator->resolve(
            $staff,
            $attendance,
            $attendance->attendance_date->format('Y-m-d'),
            $staff->schedules,
            $branchSchedule,
            graceMinutes: AttendanceStatusCalculator::graceMinutesFor($business),
            allowOvertime: AttendanceStatusCalculator::overtimeAllowedFor($business),
        );

        $history = $this->auditLogRepository->forRecord('attendances', $attendance->id);

        return new AttendanceDetailResource(['attendance' => $attendance, 'calculated' => $resolved, 'history' => $history]);
    }

    // The correction flow — only touches the fields actually supplied
    // (status/check_in_at/check_out_at/remarks), keeps attendance_date
    // fixed, and always leaves an audit_logs entry with the before/after
    // snapshot — no silent edits, per the correction requirement.
    public function correctAttendance(User $user, string $uuid, array $payload, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $attendance = $this->attendanceRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        $oldValues = $attendance->only(self::AUDIT_FIELDS);
        $date = $attendance->attendance_date->format('Y-m-d');

        if (! empty($payload['check_out_at']) && $attendance->staff && ($serving = $this->servingProblem($attendance->staff, $date))) {
            return response()->json(['message' => $serving], 422);
        }

        $updates = ['updated_by' => $user->id];

        if (array_key_exists('status', $payload)) {
            $updates['status'] = $payload['status'];
        }
        if (array_key_exists('check_in_at', $payload)) {
            $updates['check_in_at'] = $payload['check_in_at'] ? Carbon::parse($date . ' ' . $payload['check_in_at']) : null;
        }
        if (array_key_exists('check_out_at', $payload)) {
            $updates['check_out_at'] = $payload['check_out_at'] ? Carbon::parse($date . ' ' . $payload['check_out_at']) : null;
        }
        if (array_key_exists('remarks', $payload)) {
            $updates['remarks'] = $payload['remarks'];
        }

        $model = $this->attendanceRepository->updateByUuid($uuid, $updates);
        $this->logAttendanceChange($user, $model, 'Update', $oldValues, $request);
        $this->tellManagers($user, $attendance->staff, $date, $oldValues, $model->only(self::AUDIT_FIELDS));

        return $this->showDetail($user, $uuid);
    }

    public function clearAttendance(User $user, string $uuid, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        // findByUuidForBranches 404s if this uuid isn't (or isn't a staff
        // member of) one of this user's own accessible branches — delete($uuid)
        // alone wouldn't scope that check.
        $attendance = $this->attendanceRepository->findByUuidForBranches($uuid, $this->branchIds($user));
        if ($attendance->staff && ($serving = $this->servingProblem($attendance->staff, $attendance->attendance_date->format('Y-m-d')))) {
            return response()->json(['message' => $serving], 422);
        }
        $oldValues = $attendance->only(self::AUDIT_FIELDS);

        $this->auditLogRepository->record($user->id, 'attendances', $attendance->id, 'Delete', $oldValues, null, $request);

        $this->attendanceRepository->delete($uuid);
        $this->tellManagers($user, $attendance->staff, $attendance->attendance_date->format('Y-m-d'), $oldValues, null);
        return true;
    }

    // A record marked, corrected or cleared on the Management page: the branch's other managers
    // and the owner are told (not the person who did it). Never fails the change itself.
    private function tellManagers(User $user, ?Staff $staff, string $date, ?array $old, ?array $new): void
    {
        try {
            $staff?->loadMissing('branch');
            if (! $staff?->branch) {
                return;
            }
            $name = trim("{$staff->first_name} {$staff->last_name}") ?: 'A staff member';
            $text = AttendanceNotice::describe($user->displayName(), $name, $date, $old, $new);
            if (! $text) {
                return;
            }
            // About the staff member, so a click opens their attendance.
            $this->staffNotifier->branch($staff->branch, $text[0], $text[1], type: 'Attendance', roles: ['manager'], exceptUserId: $user->id, reference: $staff->uuid);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function logAttendanceChange(User $user, Attendance $model, string $action, ?array $oldValues, ?Request $request): void
    {
        $this->auditLogRepository->record(
            $user->id,
            'attendances',
            $model->id,
            $action,
            $oldValues,
            $model->only(self::AUDIT_FIELDS),
            $request
        );
    }
}
