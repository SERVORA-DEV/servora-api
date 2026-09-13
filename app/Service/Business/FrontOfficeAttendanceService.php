<?php

namespace App\Service\Business;

use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\Business\AttendanceRepository;
use App\Repository\Business\StaffRepository;
use App\Repository\SpaBusinessRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;

// Front Desk's narrow attendance surface — check-in/check-out only, no
// arbitrary status and no backdating. Deliberately separate from
// AttendanceService (the Manager/Owner surface, which accepts an arbitrary
// status and any date) rather than loosening its role gate, so Front Desk
// can never backdate a time or set a status it shouldn't — every WRITE here
// always uses now() and only ever touches today's row. Reading is a
// narrower exception to "today only": today() also accepts tomorrow's date,
// a read-only preview of the next day's expected roster (via
// StaffSchedule), never a write.
class FrontOfficeAttendanceService
{
    private const AUDIT_FIELDS = ['status', 'check_in_at', 'check_out_at', 'remarks'];

    private AttendanceRepository $attendanceRepository;
    private StaffRepository $staffRepository;
    private SpaBusinessRepository $spaBusinessRepository;
    private AttendanceStatusCalculator $statusCalculator;
    private AuditLogRepository $auditLogRepository;

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

    // Same scoping AttendanceService::branchIds uses — resolves to the
    // front officer's own staff record's branch via
    // SpaBusinessRepository::branchesForUser (already role-agnostic).
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
    }

    // Today's roster with computed status/late-minutes/work-minutes (via
    // AttendanceStatusCalculator), same as the Manager dashboard sees, so a
    // Front Desk check-in shows Late correctly without Front Desk ever
    // having to judge that themselves.
    //
    // $date optionally overrides "today" — but only ever to tomorrow (a
    // read-only preview of who's expected in, from StaffSchedule; nothing
    // to check in/out yet since checkIn()/checkOut() below still always
    // write now()). Any other date 422s — this stays a 2-day window, not a
    // historical browser; see AttendanceService::rosterReport for that.
    public function today(User $user, ?string $date = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $todayDate = now()->format('Y-m-d');
        $tomorrowDate = now()->addDay()->format('Y-m-d');
        $date = $date ?: $todayDate;

        if (! in_array($date, [$todayDate, $tomorrowDate], true)) {
            return response()->json(['message' => 'Only today and tomorrow can be viewed here.'], 422);
        }

        $branchIds = $this->branchIds($user);

        $staffMembers = $this->attendanceRepository->rosterForRange($branchIds, $date, $date);
        $branchSchedules = BranchSchedule::whereIn('spa_branch_id', $branchIds)->get()->groupBy('spa_branch_id');
        $dayOfWeek = Carbon::parse($date)->format('l');

        $data = $staffMembers->map(function ($staff) use ($date, $branchSchedules, $dayOfWeek) {
            $attendance = $staff->attendance->first();
            $branchSchedule = $branchSchedules->get($staff->spa_branch_id, collect())->firstWhere('day_of_week', $dayOfWeek);
            $resolved = $this->statusCalculator->resolve($staff, $attendance, $date, $staff->schedules, $branchSchedule);

            return [
                'staff_uuid' => $staff->uuid,
                'attendance_uuid' => $attendance?->uuid,
                'employee_number' => $staff->employee_number,
                'first_name' => $staff->first_name,
                'last_name' => $staff->last_name,
                'role' => $staff->role,
                'check_in_at' => $attendance?->check_in_at?->format('H:i'),
                'check_out_at' => $attendance?->check_out_at?->format('H:i'),
                'status' => $resolved['status'],
                'late_minutes' => $resolved['late_minutes'],
                'work_minutes' => $resolved['work_minutes'],
                'is_day_off' => $resolved['is_day_off'],
                'scheduled_start' => $resolved['scheduled_start'],
                'scheduled_end' => $resolved['scheduled_end'],
            ];
        })->values();

        return ['date' => $date, 'data' => $data];
    }

    public function checkIn(User $user, string $staffUuid, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $staff = $this->staffRepository->findByUuidForBranches($staffUuid, $this->branchIds($user));
        $date = now()->format('Y-m-d');

        $existing = Attendance::where('staff_id', $staff->id)->where('attendance_date', $date)->first();
        $oldValues = $existing?->only(self::AUDIT_FIELDS);

        // No matching StaffSchedule row today (the same "No Schedule Today"
        // case the roster read path labels via AttendanceStatusCalculator)
        // means this is an unscheduled fill-in covering for another
        // therapist, not a normal scheduled shift.
        $hasSchedule = $this->statusCalculator->hasScheduleForDate($staff->schedules, $date);

        // Only status/check_in_at/created_by/updated_by are set — an
        // existing check_out_at or remarks (unlikely this early, but
        // possible if a manager already touched today's row) are left
        // untouched by upsert()'s fill() semantics.
        $model = $this->attendanceRepository->upsert($staff->id, $date, [
            'status' => $hasSchedule ? 'Present' : 'Fill In',
            'check_in_at' => now(),
            'created_by' => $existing?->created_by ?? $user->id,
            'updated_by' => $user->id,
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'attendances',
            $model->id,
            $existing ? 'Update' : 'Create',
            $oldValues,
            $model->only(self::AUDIT_FIELDS),
            $request
        );

        return response()->json([
            'message' => 'Checked in.',
            'staff_uuid' => $staff->uuid,
            'check_in_at' => $model->check_in_at->format('H:i'),
        ]);
    }

    public function checkOut(User $user, string $staffUuid, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);

        if (! $business) {
            return response()->json(['message' => 'No spa business found for this account.'], 422);
        }

        $staff = $this->staffRepository->findByUuidForBranches($staffUuid, $this->branchIds($user));
        $date = now()->format('Y-m-d');

        $existing = Attendance::where('staff_id', $staff->id)->where('attendance_date', $date)->first();

        if (! $existing || ! $existing->check_in_at) {
            $name = trim("{$staff->first_name} {$staff->last_name}");
            return response()->json(['message' => "{$name} hasn't checked in yet today."], 422);
        }

        $oldValues = $existing->only(self::AUDIT_FIELDS);

        $model = $this->attendanceRepository->upsert($staff->id, $date, [
            'check_out_at' => now(),
            'updated_by' => $user->id,
        ]);

        $this->auditLogRepository->record(
            $user->id,
            'attendances',
            $model->id,
            'Update',
            $oldValues,
            $model->only(self::AUDIT_FIELDS),
            $request
        );

        return response()->json([
            'message' => 'Checked out.',
            'staff_uuid' => $staff->uuid,
            'check_out_at' => $model->check_out_at->format('H:i'),
        ]);
    }
}
