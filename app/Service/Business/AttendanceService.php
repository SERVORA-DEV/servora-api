<?php

namespace App\Service\Business;

use App\Http\Resources\AttendanceDetailResource;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\Staff;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\Business\AttendanceRepository;
use App\Repository\Business\StaffRepository;
use App\Repository\SpaBusinessRepository;
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

    private const AUDIT_FIELDS = ['status', 'check_in_at', 'check_out_at', 'remarks'];

    public function __construct(
        AttendanceRepository $attendanceRepository,
        StaffRepository $staffRepository,
        SpaBusinessRepository $spaBusinessRepository,
        AttendanceStatusCalculator $statusCalculator,
        AuditLogRepository $auditLogRepository,
    ) {
        $this->attendanceRepository = $attendanceRepository;
        $this->staffRepository = $staffRepository;
        $this->spaBusinessRepository = $spaBusinessRepository;
        $this->statusCalculator = $statusCalculator;
        $this->auditLogRepository = $auditLogRepository;
    }

    // Same scoping as StaffService::branchIds — owner's branch ids cover the
    // whole business; manager's cover only their own staff record's branch.
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
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

        return new AttendanceResource($model);
    }

    // Fills in "Present" only for staff with no attendance row yet for this
    // date — never overwrites an existing Late/Absent/etc. entry, so a
    // manager who already marked exceptions can safely bulk-fill the rest.
    public function bulkMarkPresent(User $user, string $date, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $roster = $this->attendanceRepository->rosterForDate($this->branchIds($user), $date);

        foreach ($roster as $staff) {
            if ($staff->attendance->isEmpty()) {
                $model = $this->attendanceRepository->upsert($staff->id, $date, [
                    'status' => 'Present',
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                $this->logAttendanceChange($user, $model, 'Create', null, $request);
            }
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

        $branchIds = $this->branchIds($user);

        $branches = Staff::whereIn('spa_branch_id', $branchIds)
            ->where('status', 'active')
            ->with('branch')
            ->get()
            ->groupBy('spa_branch_id')
            ->map(fn ($staff) => [
                'branch_uuid' => $staff->first()->branch?->uuid,
                'branch_name' => $staff->first()->branch?->branch_name,
                'active_staff_count' => $staff->count(),
            ])
            ->values();

        $records = $this->attendanceRepository->rangeForBranches($branchIds, $dateFrom, $dateTo);

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'total_staff' => $branches->sum('active_staff_count'),
            'branches' => $branches,
            'data' => $records->map(fn ($a) => [
                'staff_uuid' => $a->staff?->uuid,
                'staff_name' => trim("{$a->staff?->first_name} {$a->staff?->last_name}"),
                'role' => $a->staff?->role,
                'branch_uuid' => $a->staff?->branch?->uuid,
                'branch_name' => $a->staff?->branch?->branch_name,
                'attendance_date' => $a->attendance_date->format('Y-m-d'),
                'status' => $a->status,
                'check_in_at' => $a->check_in_at?->format('H:i'),
                'check_out_at' => $a->check_out_at?->format('H:i'),
            ])->values(),
        ];
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

        $branchIds = $this->branchIds($user);
        $staffMembers = $this->attendanceRepository->rosterForRange($branchIds, $dateFrom, $dateTo);

        // Prefetch every involved branch's weekly hours once, grouped by
        // branch, rather than querying per staff x date.
        $branchSchedulesByBranch = BranchSchedule::whereIn('spa_branch_id', $branchIds)->get()->groupBy('spa_branch_id');

        $entries = collect();

        foreach ($staffMembers as $staff) {
            $attendanceByDate = $staff->attendance->keyBy(fn ($a) => $a->attendance_date->format('Y-m-d'));
            $branchDaySchedules = $branchSchedulesByBranch->get($staff->spa_branch_id, collect());

            foreach (CarbonPeriod::create($dateFrom, $dateTo) as $day) {
                $date = $day->format('Y-m-d');
                $attendance = $attendanceByDate->get($date);
                $branchSchedule = $branchDaySchedules->firstWhere('day_of_week', $day->format('l'));

                $resolved = $this->statusCalculator->resolve($staff, $attendance, $date, $staff->schedules, $branchSchedule);

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
                    'status' => $resolved['status'],
                    'late_minutes' => $resolved['late_minutes'],
                    'is_day_off' => $resolved['is_day_off'],
                    'missing_check_in' => $resolved['missing_check_in'],
                    'missing_check_out' => $resolved['missing_check_out'],
                    'remarks' => $attendance?->remarks,
                ]);
            }
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'total_staff' => $staffMembers->count(),
            'data' => $entries->values(),
        ];
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
            $branchSchedule
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
        $oldValues = $attendance->only(self::AUDIT_FIELDS);

        $this->auditLogRepository->record($user->id, 'attendances', $attendance->id, 'Delete', $oldValues, null, $request);

        $this->attendanceRepository->delete($uuid);
        return true;
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
