<?php

namespace App\Service\Business;

use App\Models\Attendance;
use App\Models\Staff;
use App\Models\TherapistAssignment;
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
class TherapistQueueCalculator
{
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
            ->where('status', 'active')
            ->orderBy('first_name')
            ->get();

        if ($therapists->isEmpty()) {
            return collect();
        }

        $staffIds = $therapists->pluck('id')->all();

        $attendanceByStaffId = Attendance::whereIn('staff_id', $staffIds)
            ->where('attendance_date', $date)
            ->get()
            ->keyBy('staff_id');

        $lastCompletedByStaffId = $this->lastCompletedAt($staffIds, $date);
        $activeStatusByStaffId = $this->activeAssignmentStatus($staffIds, $date);

        [$onFloor, $offFloor] = $therapists
            ->map(function (Staff $staff) use ($attendanceByStaffId, $lastCompletedByStaffId, $activeStatusByStaffId) {
                $attendance = $attendanceByStaffId->get($staff->id);

                // On the floor = timed in and not yet timed out. Timing out is
                // how a therapist leaves the rotation for the day; note nothing
                // else in the codebase reads check_out_at this way yet (the
                // assignment guards only look at check_in_at), so this is the
                // first place it carries that meaning.
                $onFloor = $attendance?->check_in_at !== null && $attendance?->check_out_at === null;

                return [
                    'staff_id' => $staff->id,
                    'uuid' => $staff->uuid,
                    'name' => trim("{$staff->first_name} {$staff->last_name}"),
                    'first_name' => $staff->first_name,
                    'position' => null,
                    'rotation_status' => $activeStatusByStaffId[$staff->id] ?? 'free',
                    'checked_in_at' => $attendance?->check_in_at,
                    'last_completed_at' => $lastCompletedByStaffId[$staff->id] ?? null,
                    'in_queue' => $onFloor,
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

    /** The first row that is in the queue and genuinely takeable, or null when everyone is occupied. */
    public function nextUp(Collection $rows): ?array
    {
        return $rows->first(fn (array $row) => $row['in_queue'] && $row['rotation_status'] === 'free');
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
        return TherapistAssignment::query()
            ->whereIn('staff_id', $staffIds)
            ->where('assignment_status', 'Completed')
            ->whereDate('completed_at', $date)
            ->selectRaw('staff_id, MAX(completed_at) as last_completed_at')
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
     * service moves a therapist to the back. A client who cancels or no-shows
     * therefore never costs their therapist a turn.
     *
     * @return array<int, string>
     */
    private function activeAssignmentStatus(array $staffIds, string $date): array
    {
        $rows = TherapistAssignment::query()
            ->whereIn('staff_id', $staffIds)
            ->whereIn('assignment_status', ['In Progress', 'Assigned'])
            // The parent service must still be live. Cancelling an appointment
            // already cancels its Assigned rows (see cancelAppointment), but
            // guarding on the service too means a stray row can never pin a
            // therapist as occupied when there's nothing left to do.
            ->whereHas('appointmentService', fn ($q) => $q->whereIn('status', ['Pending', 'In Progress']))
            ->whereHas('appointmentService.appointment', fn ($q) => $q->whereDate('appointment_date', $date))
            ->get(['staff_id', 'assignment_status']);

        $statuses = [];

        foreach ($rows as $row) {
            // In Progress outranks Assigned: a therapist mid-service who also
            // has a later booking lined up is busy right now, which is the
            // more useful thing for the front desk to see.
            if ($row->assignment_status === 'In Progress' || ! isset($statuses[$row->staff_id])) {
                $statuses[$row->staff_id] = $row->assignment_status === 'In Progress' ? 'in_service' : 'assigned';
            }
        }

        return $statuses;
    }
}
