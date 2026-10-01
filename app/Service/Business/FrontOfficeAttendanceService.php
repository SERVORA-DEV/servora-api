<?php

namespace App\Service\Business;

use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\SpaBusiness;
use App\Models\SpaBusinessSetting;
use App\Models\Staff;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\Business\AttendanceRepository;
use App\Repository\Business\StaffRepository;
use App\Repository\SpaBusinessRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;

// Front Desk's narrow attendance surface — check-in/check-out, sending
// someone home early or marking them on leave for today, and checking in a
// fill-in who covers for them. No arbitrary status and no backdating: every
// WRITE here uses now() and only ever touches today's row. Deliberately
// separate from AttendanceService (the Manager/Owner surface, which accepts
// an arbitrary status and any date) rather than loosening its role gate.
// Reading is wider than writing: today() also accepts tomorrow's date (a
// read-only preview of who's expected), and history() lists past days.
//
// Earnings shown alongside (CommissionCalculator) are what lets the front
// desk hand a fill-in shift to the therapist who has earned the least.
class FrontOfficeAttendanceService
{
    private const AUDIT_FIELDS = ['status', 'check_in_at', 'check_out_at', 'left_early', 'covering_for_staff_id', 'remarks'];

    public function __construct(
        private AttendanceRepository $attendanceRepository,
        private StaffRepository $staffRepository,
        private SpaBusinessRepository $spaBusinessRepository,
        private AttendanceStatusCalculator $statusCalculator,
        private AuditLogRepository $auditLogRepository,
        private CommissionCalculator $commissionCalculator,
        private StaffNotifier $staffNotifier,
    ) {
    }

    // Same scoping AttendanceService::branchIds uses — resolves to the
    // front officer's own staff record's branch via
    // SpaBusinessRepository::branchesForUser (already role-agnostic).
    private function branchIds(User $user): array
    {
        return $this->spaBusinessRepository->branchesForUser($user)->pluck('id')->all();
    }

    private function noBusiness()
    {
        return response()->json(['message' => 'No spa business found for this account.'], 422);
    }

    // Today's roster with computed status/late-minutes/work-minutes (via
    // AttendanceStatusCalculator), same as the Manager dashboard sees, plus
    // each therapist's commission earnings and who is covering for whom.
    //
    // $date optionally overrides "today" — but only ever to tomorrow (a
    // read-only preview of who's expected in, from StaffSchedule). Any other
    // date 422s; past days are history()'s job.
    public function today(User $user, ?string $date = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        $todayDate = now()->format('Y-m-d');
        $tomorrowDate = now()->addDay()->format('Y-m-d');
        $date = $date ?: $todayDate;

        if (! in_array($date, [$todayDate, $tomorrowDate], true)) {
            return response()->json(['message' => 'Only today and tomorrow can be viewed here.'], 422);
        }

        $branchIds = $this->branchIds($user);
        $staffMembers = $this->attendanceRepository->rosterForRange($branchIds, $date, $date);

        return [
            'date' => $date,
            'data' => $this->rows($business, $staffMembers, $date, $branchIds),
            'commission' => $this->commissionMeta($business),
            'shift_swaps' => $this->shiftSwapsAllowed($business),
        ];
    }

    public function checkIn(User $user, string $staffUuid, ?string $coveringForUuid = null, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        $branchIds = $this->branchIds($user);
        $staff = $this->staffRepository->findByUuidForBranches($staffUuid, $branchIds);
        $date = now()->format('Y-m-d');

        $existing = Attendance::where('staff_id', $staff->id)->where('attendance_date', $date)->first();
        if ($existing?->check_in_at && ! $existing->check_out_at) {
            return response()->json(['message' => $this->nameOf($staff) . ' is already checked in.'], 422);
        }
        $oldValues = $existing?->only(self::AUDIT_FIELDS);

        $coveringFor = null;
        if ($coveringForUuid) {
            // Settings → Staff & Permissions → Shift swaps. Coming in for
            // extra hours (no one covered) is still allowed.
            if (! $this->shiftSwapsAllowed($business)) {
                return response()->json(['message' => 'Shift swaps are turned off, so staff can\'t cover for each other. The owner can turn them on in Settings → Staff & Permissions.'], 422);
            }
            $coveringFor = $this->staffRepository->findByUuidForBranches($coveringForUuid, $branchIds);
            if ($coveringFor->id === $staff->id) {
                return response()->json(['message' => 'A therapist can\'t cover for themselves.'], 422);
            }
        }

        // No working shift today — no StaffSchedule row, or it's their day
        // off — means this is a fill-in (covering for someone, or picking up
        // extra hours), not a normal scheduled shift. Covering for someone
        // always counts as a fill-in too.
        $hasShift = $this->statusCalculator->hasWorkingScheduleForDate($staff->schedules, $date);
        $status = ($hasShift && ! $coveringFor) ? 'Present' : 'Fill In';

        // Coming back after checking out (e.g. a second fill-in stint) keeps
        // the original check-in time and just re-opens the day.
        $payload = $existing?->check_in_at && $existing->check_out_at
            ? ['check_out_at' => null, 'left_early' => false]
            : ['check_in_at' => now(), 'status' => $status, 'left_early' => false];

        $model = $this->attendanceRepository->upsert($staff->id, $date, $payload + [
            'covering_for_staff_id' => $coveringFor?->id ?? $existing?->covering_for_staff_id,
            'created_by' => $existing?->created_by ?? $user->id,
            'updated_by' => $user->id,
        ]);

        $this->audit($user, $model, $existing, $oldValues, $request);

        if ($coveringFor) {
            $this->notifyBranch($staff, $user, 'Fill-in checked in', $this->nameOf($staff) . ' is covering for ' . $this->nameOf($coveringFor) . ' today.');
        }

        return $this->mutationResponse('Checked in.', $business, $staff, $branchIds);
    }

    public function checkOut(User $user, string $staffUuid, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        $branchIds = $this->branchIds($user);
        $staff = $this->staffRepository->findByUuidForBranches($staffUuid, $branchIds);
        $date = now()->format('Y-m-d');

        $existing = Attendance::where('staff_id', $staff->id)->where('attendance_date', $date)->first();

        if (! $existing || ! $existing->check_in_at) {
            return response()->json(['message' => $this->nameOf($staff) . " hasn't checked in yet today."], 422);
        }
        if ($existing->check_out_at) {
            return response()->json(['message' => $this->nameOf($staff) . ' is already checked out.'], 422);
        }

        $oldValues = $existing->only(self::AUDIT_FIELDS);

        $model = $this->attendanceRepository->upsert($staff->id, $date, [
            'check_out_at' => now(),
            'updated_by' => $user->id,
        ]);

        $this->audit($user, $model, $existing, $oldValues, $request);

        return $this->mutationResponse('Checked out.', $business, $staff, $branchIds);
    }

    // A therapist asking to go home. 'early' = they're checked in and leave
    // now (checked out, flagged left_early); 'day' = they won't come in at
    // all today (On Leave). Either way the reason is kept in remarks, the
    // manager and front desk are told, and the response carries fill-in
    // candidates so the front desk can hand the rest of the day to someone.
    public function leave(User $user, string $staffUuid, string $type, ?string $reason, ?Request $request = null)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        $branchIds = $this->branchIds($user);
        $staff = $this->staffRepository->findByUuidForBranches($staffUuid, $branchIds);
        $date = now()->format('Y-m-d');
        $name = $this->nameOf($staff);

        $existing = Attendance::where('staff_id', $staff->id)->where('attendance_date', $date)->first();
        $oldValues = $existing?->only(self::AUDIT_FIELDS);
        $remarks = trim(($type === 'early' ? 'Left early' : 'On leave') . ($reason ? ": {$reason}" : ''));

        if ($type === 'early') {
            if (! $existing?->check_in_at) {
                return response()->json(['message' => "{$name} hasn't checked in yet — mark them on leave instead."], 422);
            }
            if ($existing->check_out_at) {
                return response()->json(['message' => "{$name} is already checked out."], 422);
            }
            $payload = ['check_out_at' => now(), 'left_early' => true];
        } else {
            if ($existing?->check_in_at && ! $existing->check_out_at) {
                return response()->json(['message' => "{$name} is checked in — use \"Leaving early\" instead."], 422);
            }
            $payload = ['status' => 'On Leave'];
        }

        $model = $this->attendanceRepository->upsert($staff->id, $date, $payload + [
            'remarks' => $remarks,
            'created_by' => $existing?->created_by ?? $user->id,
            'updated_by' => $user->id,
        ]);

        $this->audit($user, $model, $existing, $oldValues, $request);

        $this->notifyBranch(
            $staff,
            $user,
            $type === 'early' ? 'Therapist left early' : 'Therapist on leave today',
            ($type === 'early' ? "{$name} went home early" : "{$name} is on leave today") . ($reason ? " — {$reason}." : '.'),
        );

        $response = $this->mutationResponse($type === 'early' ? 'Marked as leaving early.' : 'Marked on leave.', $business, $staff, $branchIds);
        $response['candidates'] = $this->candidateRows($business, $branchIds, excludeStaffId: $staff->id);

        return $response;
    }

    // Therapists who could fill in right now — active at this branch and not
    // on the floor, not on leave and not sent home today — least earned this
    // pay period first, so extra shifts go to whoever needs them most.
    public function fillInCandidates(User $user)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        return [
            'data' => $this->candidateRows($business, $this->branchIds($user)),
            'commission' => $this->commissionMeta($business),
        ];
    }

    // One staff member's recent days (newest first) and earnings — the
    // per-staff attendance page. Read-only, own branch only.
    public function history(User $user, string $staffUuid, int $days = 30)
    {
        $business = $this->spaBusinessRepository->findForUser($user);
        if (! $business) {
            return $this->noBusiness();
        }

        $staff = $this->staffRepository->findByUuidForBranches($staffUuid, $this->branchIds($user));
        $staff->loadMissing('schedules');

        $to = now()->startOfDay();
        $from = $to->copy()->subDays(max(1, min($days, 90)) - 1);

        $attendanceByDate = Attendance::with('coveringFor')
            ->where('staff_id', $staff->id)
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn ($a) => $a->attendance_date->format('Y-m-d'));
        $branchSchedules = BranchSchedule::where('spa_branch_id', $staff->spa_branch_id)->get();
        $grace = AttendanceStatusCalculator::graceMinutesFor($business);
        $dailyEarnings = $this->commissionCalculator->dailyEarnings($business, $staff->id, $from, $to);

        // Days before they joined aren't absences — nothing was expected of
        // someone who didn't work here yet.
        $joined = Carbon::parse($staff->hire_date ?? $staff->created_at)->startOfDay();
        $notYet = ['status' => null, 'late_minutes' => null, 'work_minutes' => null, 'is_day_off' => false, 'scheduled_start' => null, 'scheduled_end' => null];

        $rows = [];
        for ($day = $to->copy(); $day->gte($from); $day->subDay()) {
            $date = $day->format('Y-m-d');
            $attendance = $attendanceByDate->get($date);
            $resolved = ($day->lt($joined) && ! $attendance) ? $notYet : $this->statusCalculator->resolve(
                $staff,
                $attendance,
                $date,
                $staff->schedules,
                $branchSchedules->firstWhere('day_of_week', $day->format('l')),
                graceMinutes: $grace,
            );

            $rows[] = [
                'date' => $date,
                'check_in_at' => $attendance?->check_in_at?->format('H:i'),
                'check_out_at' => $attendance?->check_out_at?->format('H:i'),
                'status' => $resolved['status'],
                'late_minutes' => $resolved['late_minutes'],
                'work_minutes' => $resolved['work_minutes'],
                'is_day_off' => $resolved['is_day_off'],
                'scheduled_start' => $resolved['scheduled_start'],
                'scheduled_end' => $resolved['scheduled_end'],
                'remarks' => $attendance?->remarks,
                'covering_for_name' => $attendance?->coveringFor ? $this->nameOf($attendance->coveringFor) : null,
                'earned' => $dailyEarnings[$date] ?? null,
            ];
        }

        $earnings = $this->commissionCalculator->earnings($business, [$staff->id])[$staff->id] ?? null;
        $worked = collect($rows)->filter(fn ($r) => $r['check_in_at'] !== null);

        return [
            'staff' => [
                'uuid' => $staff->uuid,
                'first_name' => $staff->first_name,
                'last_name' => $staff->last_name,
                'employee_number' => $staff->employee_number,
                'role' => $staff->role,
            ],
            'days' => $rows,
            'summary' => [
                'days_worked' => $worked->count(),
                'late_days' => $worked->where('status', 'Late')->count(),
                'fill_in_days' => $worked->where('status', 'Fill In')->count(),
                'absent_days' => collect($rows)->where('status', 'Absent')->count(),
                'leave_days' => collect($rows)->where('status', 'On Leave')->count(),
                'earned_today' => $earnings['today'] ?? null,
                'earned_period' => $earnings['period'] ?? null,
                'earned_range' => $dailyEarnings ? round(array_sum($dailyEarnings), 2) : null,
            ],
            'commission' => $this->commissionMeta($business),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function rows(SpaBusiness $business, $staffMembers, string $date, array $branchIds): array
    {
        $branchSchedules = BranchSchedule::whereIn('spa_branch_id', $branchIds)->get()->groupBy('spa_branch_id');
        $dayOfWeek = Carbon::parse($date)->format('l');
        $grace = AttendanceStatusCalculator::graceMinutesFor($business);
        $earnings = $this->commissionCalculator->earnings($business, $staffMembers->pluck('id')->all());

        // Who is covering for whom on this date, both directions — read off
        // the attendance rows the roster already loaded (no extra queries;
        // the database round trip is the expensive part of this endpoint).
        $byId = $staffMembers->keyBy('id');
        $coveredBy = [];
        foreach ($staffMembers as $member) {
            $coveringId = $member->attendance->first()?->covering_for_staff_id;
            if ($coveringId) {
                $coveredBy[$coveringId] = $this->nameOf($member);
            }
        }

        return $staffMembers->map(function (Staff $staff) use ($date, $branchSchedules, $dayOfWeek, $grace, $earnings, $byId, $coveredBy) {
            $attendance = $staff->attendance->first();
            $branchSchedule = $branchSchedules->get($staff->spa_branch_id, collect())->firstWhere('day_of_week', $dayOfWeek);
            $resolved = $this->statusCalculator->resolve($staff, $attendance, $date, $staff->schedules, $branchSchedule, graceMinutes: $grace);
            $coveringId = $attendance?->covering_for_staff_id;
            // A single-row roster (after a write) won't hold the covered person.
            $covering = $coveringId ? ($byId->get($coveringId) ?? Staff::find($coveringId, ['id', 'first_name', 'last_name'])) : null;

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
                'remarks' => $attendance?->remarks,
                'covering_for_name' => $covering ? $this->nameOf($covering) : null,
                'covered_by_name' => $coveredBy[$staff->id] ?? null,
                'earned_today' => $earnings[$staff->id]['today'] ?? null,
                'earned_period' => $earnings[$staff->id]['period'] ?? null,
            ];
        })->values()->all();
    }

    private function candidateRows(SpaBusiness $business, array $branchIds, ?int $excludeStaffId = null): array
    {
        $date = now()->format('Y-m-d');
        $therapists = $this->attendanceRepository->rosterForRange($branchIds, $date, $date)
            ->where('role', 'therapist')
            ->reject(fn (Staff $s) => $s->id === $excludeStaffId)
            ->filter(function (Staff $s) {
                $a = $s->attendance->first();
                $onFloor = $a?->check_in_at && ! $a->check_out_at;

                return ! $onFloor && $a?->status !== 'On Leave' && ! $a?->left_early;
            })
            ->values();

        $earnings = $this->commissionCalculator->earnings($business, $therapists->pluck('id')->all());

        return $therapists
            ->map(function (Staff $s) use ($date, $earnings) {
                $hasShift = $this->statusCalculator->hasWorkingScheduleForDate($s->schedules, $date);
                $isDayOff = $this->statusCalculator->hasScheduleForDate($s->schedules, $date) && ! $hasShift;

                return [
                    'staff_uuid' => $s->uuid,
                    'first_name' => $s->first_name,
                    'last_name' => $s->last_name,
                    'employee_number' => $s->employee_number,
                    // 'scheduled' (hasn't arrived yet), 'day_off' or 'unscheduled'.
                    'availability' => $hasShift ? 'scheduled' : ($isDayOff ? 'day_off' : 'unscheduled'),
                    'earned_today' => $earnings[$s->id]['today'] ?? null,
                    'earned_period' => $earnings[$s->id]['period'] ?? null,
                ];
            })
            ->sortBy([
                fn ($a, $b) => ($a['earned_period'] ?? 0) <=> ($b['earned_period'] ?? 0),
                fn ($a, $b) => strcmp($a['first_name'] ?? '', $b['first_name'] ?? ''),
            ])
            ->values()
            ->all();
    }

    // The updated roster row for one staff member, so the page can swap it
    // in place instead of refetching the whole roster after every tap.
    private function mutationResponse(string $message, SpaBusiness $business, Staff $staff, array $branchIds): array
    {
        $date = now()->format('Y-m-d');
        $fresh = $this->attendanceRepository->rosterForRange([$staff->spa_branch_id], $date, $date)
            ->where('id', $staff->id);

        return [
            'message' => $message,
            'staff_uuid' => $staff->uuid,
            'row' => $this->rows($business, $fresh, $date, $branchIds)[0] ?? null,
        ];
    }

    // Settings → Staff & Permissions → Shift swaps: whether one staff member
    // may cover another's shift (a fill-in with covering_for).
    private function shiftSwapsAllowed(SpaBusiness $business): bool
    {
        $business->loadMissing('settings');
        $policy = $business->settings?->section('staff_policy') ?? SpaBusinessSetting::DEFAULTS['staff_policy'];

        return (bool) ($policy['allow_shift_swap'] ?? false);
    }

    private function commissionMeta(SpaBusiness $business): array
    {
        $policy = $this->commissionCalculator->policy($business);

        return [
            'enabled' => $policy !== null,
            'period_label' => $this->commissionCalculator->periodLabel($policy),
        ];
    }

    private function audit(User $user, Attendance $model, ?Attendance $existing, ?array $oldValues, ?Request $request): void
    {
        $this->auditLogRepository->record(
            $user->id,
            'attendances',
            $model->id,
            $existing ? 'Update' : 'Create',
            $oldValues,
            $model->only(self::AUDIT_FIELDS),
            $request
        );
    }

    private function notifyBranch(Staff $staff, User $actor, string $title, string $message): void
    {
        $staff->loadMissing('branch');
        if ($staff->branch) {
            $this->staffNotifier->branch($staff->branch, $title, $message, type: 'Attendance', exceptUserId: $actor->id);
        }
    }

    private function nameOf(?Staff $staff): string
    {
        return trim(($staff?->first_name ?? '') . ' ' . ($staff?->last_name ?? '')) ?: 'This staff member';
    }
}
