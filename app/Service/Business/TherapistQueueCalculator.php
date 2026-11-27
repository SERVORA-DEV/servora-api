<?php

namespace App\Service\Business;

use App\Enums\AppointmentServiceStatus;
use App\Enums\AssignmentStatus;
use App\Enums\StaffStatus;
use App\Models\Attendance;
use App\Models\SpaBranch;
use App\Models\Staff;
use App\Models\StaffTimeOff;
use App\Models\TherapistAssignment;
use App\Repository\Business\AttendanceRepository;
use App\Support\Branches\BusinessDay;
use App\Support\Visits\RotationTurn;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Single source of truth for the therapist rotation — the fair-turn order a
// spa floor actually runs on: whoever timed in first takes the next client,
// and once they've finished a service they go to the back of the line.
// Called from every read path that shows the rotation (the appointment
// therapist picker and the front-desk queue board) so the two can never
// disagree about whose turn it is. Deliberately stateless/pure: no writes,
// no side effects, safe to call for a date with no attendance at all.
//
// The rotation is ADVISORY, never a gate. Clients have preferences — gender,
// a therapist they've had before — so the front desk must always be able to
// assign out of turn. Nothing here blocks an assignment; it only says who
// would be next if nobody had a preference.
//
// There is one order for every spa: time-in order. The earnings each person
// has made are carried along for the front desk to see, but never decide who
// is next.
class TherapistQueueCalculator
{
    public function __construct(
        private CommissionCalculator $commissionCalculator,
        private AttendanceRepository $attendanceRepository,
    )
    {
    }

    /**
     * The rotation for one branch on one date, in turn order.
     *
     * Every therapist at the branch comes back — the ones on the floor first,
     * in rotation order with a 1-based `position`, then the ones who haven't
     * timed in (or have timed out) with `in_queue => false` and no position.
     * Nobody is hidden: a therapist who forgot to time in should be visibly
     * unavailable rather than silently absent.
     *
     * @param  Collection<int, Staff>|null  $therapists  the branch's active therapists, when the caller has already loaded them (therapistOptions does) — re-queried here otherwise.
     * @return Collection<int, array{staff_id:int, uuid:string, name:string, position:?int, rotation_status:string, checked_in_at:?Carbon, last_completed_at:?Carbon, in_queue:bool}>
     */
    public function forBranch(int $branchId, string $date, ?Collection $therapists = null): Collection
    {
        $therapists ??= Staff::where('spa_branch_id', $branchId)
            ->where('role', 'therapist')
            ->where('status', StaffStatus::Active->value)
            ->orderBy('first_name')
            ->get();

        if ($therapists->isEmpty()) {
            return collect();
        }

        $staffIds = $therapists->pluck('id')->all();

        $attendanceDate = BusinessDay::attendanceDate($branchId, $date);
        $attendanceByStaffId = Attendance::whereIn('staff_id', $staffIds)
            ->where('attendance_date', $attendanceDate)
            ->get()
            ->keyBy('staff_id');
        if ($date === now()->toDateString()) {
            $attendanceByStaffId = $attendanceByStaffId->replace(
                $this->attendanceRepository->openAtBranches([$branchId], $staffIds, $attendanceDate),
            );
        }

        $timeOffByStaffId = $this->timeOffOn($staffIds, $date);
        $lastCompletedByStaffId = $this->lastCompletedAt($staffIds, $date);
        $activeStatusByStaffId = $this->activeAssignmentStatus($staffIds, $date);

        $business = SpaBranch::with('business.settings')->find($branchId)?->business;
        $earnings = $this->commissionCalculator->earnings($business, $staffIds);

        [$onFloor, $offFloor] = $therapists
            ->map(function (Staff $staff) use ($attendanceByStaffId, $timeOffByStaffId, $lastCompletedByStaffId, $activeStatusByStaffId, $earnings) {
                $attendance = $attendanceByStaffId->get($staff->id);

                // Timing out takes the therapist out of the rotation.
                // Off that day in Employees (leave, sick, absent) is never in the line, even
                // when timed in (a time in made before the leave was set).
                $timeOff = $timeOffByStaffId[$staff->id] ?? null;
                $onFloor = $attendance?->check_in_at !== null && $attendance?->check_out_at === null && $timeOff === null;

                return [
                    'staff_id' => $staff->id,
                    'uuid' => $staff->uuid,
                    'name' => trim("{$staff->first_name} {$staff->last_name}"),
                    'first_name' => $staff->first_name,
                    // What they do, main one first (App\Support\Staff\StaffSpecialty).
                    'specialties' => $staff->specialtyList(),
                    'position' => null,
                    'rotation_status' => $activeStatusByStaffId[$staff->id] ?? 'free',
                    'checked_in_at' => $attendance?->check_in_at,
                    'last_completed_at' => $lastCompletedByStaffId[$staff->id] ?? null,
                    'in_queue' => $onFloor,
                    // "On leave", "Sick", "Absent" or "Time off" when off that day; null otherwise.
                    'time_off' => $timeOff,
                    // null when commission is turned off.
                    'earned_today' => $earnings[$staff->id]['today'] ?? null,
                    'earned_period' => $earnings[$staff->id]['period'] ?? null,
                ];
            })
            ->partition(fn (array $row) => $row['in_queue']);

        return $onFloor
            ->sortBy([
                // The whole rotation rule, in one key. A therapist who hasn't
                // finished anything today sorts by when they timed in; one who
                // has sorts by when they last finished — which is exactly
                // "go to the back of the line", without needing a stored
                // position to shuffle. Someone who arrives later than another
                // therapist's last completion is correctly BEHIND them: they
                // joined the line after that therapist rejoined it.
                fn (array $a, array $b) => $this->rotationKey($a) <=> $this->rotationKey($b),
                // Two therapists who timed in on the same second (or a
                // completed_at collision on a multi-therapist service) fall
                // back to time-in, then name, so the order is stable between
                // requests rather than left to row order.
                fn (array $a, array $b) => ($a['checked_in_at']?->getTimestamp() ?? 0) <=> ($b['checked_in_at']?->getTimestamp() ?? 0),
                fn (array $a, array $b) => strcmp((string) $a['first_name'], (string) $b['first_name']),
            ])
            ->values()
            ->map(fn (array $row, int $index) => array_merge($row, ['position' => $index + 1]))
            // Off the floor keeps its name ordering and never gets a position —
            // there's no turn to be in when you're not here.
            ->concat($offFloor->values())
            ->map(fn (array $row) => collect($row)->except('first_name')->all())
            ->values();
    }

    /**
     * The first row in the queue who can take the next client, or null when
     * everyone is mid-service. Someone assigned to a booking that hasn't
     * started is still next up — see App\Support\Visits\RotationTurn.
     */
    public function nextUp(Collection $rows): ?array
    {
        return RotationTurn::nextUp($rows);
    }

    /**
     * The time-off label per staff member whose time off covers $date (one query for the roster).
     *
     * @return array<int, string>
     */
    private function timeOffOn(array $staffIds, string $date): array
    {
        if (! StaffTimeOff::ready()) {
            return [];
        }

        return StaffTimeOff::whereIn('staff_id', $staffIds)
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->get(['staff_id', 'type'])
            ->mapWithKeys(fn (StaffTimeOff $t) => [$t->staff_id => StaffTimeOff::LABELS[$t->type] ?? 'Time off'])
            ->all();
    }

    private function rotationKey(array $row): int
    {
        return ($row['last_completed_at'] ?? $row['checked_in_at'])?->getTimestamp() ?? 0;
    }

    /**
     * MAX(completed_at) per staff member for $date — one grouped query for the
     * whole roster rather than one per therapist.
     *
     * Scoped on completed_at's own date rather than the appointment's booking
     * date: the rotation is about what actually happened on the floor today,
     * and a service finished today off a booking made for another date still
     * took the therapist's turn.
     *
     * @return array<int, Carbon>
     */
    private function lastCompletedAt(array $staffIds, string $date): array
    {
        // A therapist who handed a paused service over to someone else also
        // goes to the back, from the moment of the hand-over (updated_at: the
        // row is final from then on). Their completed_at is when they stopped
        // working, which can be long before: the client was paused meanwhile
        // and the therapist stayed with them.
        $handedOver = AssignmentStatus::HandedOver->value;
        $turnAt = "CASE WHEN assignment_status = '{$handedOver}' THEN updated_at ELSE completed_at END";

        return TherapistAssignment::query()
            ->whereIn('staff_id', $staffIds)
            ->whereIn('assignment_status', [AssignmentStatus::Completed->value, $handedOver])
            ->whereRaw("DATE({$turnAt}) = ?", [$date])
            ->selectRaw("staff_id, MAX({$turnAt}) as last_completed_at")
            ->groupBy('staff_id')
            ->pluck('last_completed_at', 'staff_id')
            ->map(fn ($value) => Carbon::parse($value))
            ->all();
    }

    /**
     * 'in_service' | 'assigned' per staff member — absent means free.
     *
     * 'in_service' is the same predicate as
     * AppointmentAvailabilityService::isStaffCurrentlyBusy(), but scoped to
     * $date: that method has no date scoping at all, so a stale In Progress
     * row left over from a previous day would mark a therapist permanently
     * busy. The rotation must not inherit that.
     *
     * Note both states keep their position in the queue — only completing a
     * service (or handing a paused one over) moves a therapist to the back. A client who cancels or no-shows
     * therefore never costs their therapist a turn. And only 'in_service'
     * takes them out of "next up": someone merely assigned is standing free
     * (App\Support\Visits\RotationTurn).
     *
     * @return array<int, string>
     */
    private function activeAssignmentStatus(array $staffIds, string $date): array
    {
        $rows = TherapistAssignment::query()
            ->whereIn('staff_id', $staffIds)
            ->whereIn('assignment_status', [AssignmentStatus::InProgress->value, AssignmentStatus::Assigned->value])
            // The parent service must still be live. Cancelling an appointment
            // already cancels its Assigned rows (see cancelAppointment), but
            // guarding on the service too means a stray row can never pin a
            // therapist as occupied when there's nothing left to do.
            ->whereHas('appointmentService', fn ($q) => $q->whereIn('status', [AppointmentServiceStatus::Pending->value, AppointmentServiceStatus::InProgress->value]))
            ->whereHas('appointmentService.appointment', fn ($q) => $q->whereDate('appointment_date', $date))
            ->get(['staff_id', 'assignment_status']);

        $statuses = [];
        // On the way back from a home visit: not free to take someone yet.
        if ($date === now()->toDateString()) {
            foreach (array_keys(HomeServiceRules::returnTrips($staffIds, now(), now()->copy()->addMinute())) as $staffId) {
                $statuses[$staffId] = 'in_service';
            }
        }

        foreach ($rows as $row) {
            // In Progress outranks Assigned: a therapist mid-service who also
            // has a later booking lined up is busy right now, which is the
            // more useful thing for the front desk to see.
            if ($row->assignment_status === AssignmentStatus::InProgress->value || ! isset($statuses[$row->staff_id])) {
                $statuses[$row->staff_id] = $row->assignment_status === AssignmentStatus::InProgress->value ? 'in_service' : 'assigned';
            }
        }

        return $statuses;
    }
}
