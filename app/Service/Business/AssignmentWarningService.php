<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\BranchSchedule;
use App\Models\Staff;
use App\Models\TherapistAssignment;
use App\Support\BusinessDay;
use Carbon\Carbon;

// The warnings shown next to a booking's assigned staff on the front desk: the assigned person is on
// leave, absent, late, or on their day off (StaffAttendanceWarning decides each). Only bookings still to
// be served — scheduled or checked in, today or later — with staff assigned and not started are looked at.
//
// Asked for a whole list at once (preload) it costs a few queries for everyone instead of a few per
// booking; the appointment resource reads from it. A booking that was not preloaded (a detail or an
// action's answer) is worked out fresh every time, so it never shows assignments from before a change.
//
// Held for the request only (scoped in the container), like BookingPolicy's cache.
class AssignmentWarningService
{
    private const CACHE = 'assignment-warnings';

    /** appointment id => warnings, for the ones a list asked for together */
    private array $preloaded = [];

    public function __construct(private StaffAttendanceWarning $standing)
    {
    }

    public static function instance(): self
    {
        if (! app()->bound(self::CACHE)) {
            app()->scoped(self::CACHE, fn () => app(self::class));
        }

        return app(self::CACHE);
    }

    /**
     * @return array<int, array{staff_uuid: string, service_uuids: array<int, string>, kind: string, message: string, minutes: ?int}>
     */
    public function for(Appointment $appointment): array
    {
        return $this->preloaded[$appointment->id] ?? $this->compute([$appointment])[$appointment->id] ?? [];
    }

    /** @param  iterable<int, Appointment>  $appointments */
    public function preload(iterable $appointments): void
    {
        $this->preloaded = $this->compute($appointments) + $this->preloaded;
    }

    /**
     * @param  iterable<int, Appointment>  $appointments
     * @return array<int, array<int, array<string, mixed>>> appointment id => warnings
     */
    private function compute(iterable $appointments): array
    {
        $today = now()->format('Y-m-d');
        $wanted = [];
        $staffIds = [];
        $memo = [];

        foreach ($appointments as $appointment) {
            $memo[$appointment->id] = [];
            if (! in_array($appointment->status, [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CHECKED_IN], true)
                || $appointment->appointment_date->format('Y-m-d') < $today) {
                continue;
            }
            $held = $this->heldStaff($appointment);
            if (! $held) {
                continue;
            }
            $wanted[] = [$appointment, $held];
            foreach ($held as $row) {
                $staffIds[$row['staff']->id] = true;
            }
        }
        if (! $wanted) {
            return $memo;
        }

        $staff = Staff::whereIn('id', array_keys($staffIds))->with('schedules')->get()->keyBy('id');
        $attendance = [];
        $branchSchedules = [];
        $grace = [];

        foreach ($wanted as [$appointment, $held]) {
            $date = $appointment->appointment_date->format('Y-m-d');
            $branchId = $appointment->spa_branch_id;

            // Today's attendance, once for everyone on the branch's business day (nothing exists for later days).
            if ($date === $today && ! isset($attendance[$branchId][$date])) {
                $attendance[$branchId][$date] = Attendance::whereIn('staff_id', array_keys($staffIds))
                    ->where('attendance_date', BusinessDay::attendanceDate($branchId, $date))
                    ->get()->keyBy('staff_id');
            }
            $dayKey = $branchId . '|' . $date;
            $branchSchedules[$dayKey] ??= BranchSchedule::where('spa_branch_id', $branchId)->where('day_of_week', Carbon::parse($date)->format('l'))->first();
            $grace[$branchId] ??= AttendanceStatusCalculator::graceMinutesFor($appointment->branch?->business);

            // One warning per staff member, naming the services they are on.
            $perStaff = [];
            foreach ($held as $row) {
                $perStaff[$row['staff']->id][] = $row['service_uuid'];
            }
            foreach ($perStaff as $staffId => $serviceUuids) {
                $person = $staff->get($staffId);
                if (! $person) {
                    continue;
                }
                $found = $this->standing->check(
                    $person,
                    $attendance[$branchId][$date][$person->id] ?? null,
                    $date,
                    $person->schedules,
                    $branchSchedules[$dayKey],
                    $grace[$branchId],
                );
                if ($found) {
                    $memo[$appointment->id][] = [
                        'staff_uuid' => $person->uuid,
                        'service_uuids' => $serviceUuids,
                        'kind' => $found['kind'],
                        'message' => $found['message'],
                        'minutes' => $found['minutes'],
                    ];
                }
            }
        }

        return $memo;
    }

    /** The staff assigned (not started) to this booking's services. @return array<int, array{staff: Staff, service_uuid: string}> */
    private function heldStaff(Appointment $appointment): array
    {
        $rows = [];
        foreach ($appointment->services as $service) {
            if ($service->status !== 'Pending') {
                continue;
            }
            foreach ($service->therapistAssignments as $assignment) {
                /** @var TherapistAssignment $assignment */
                if ($assignment->assignment_status === 'Assigned' && $assignment->staff) {
                    $rows[] = ['staff' => $assignment->staff, 'service_uuid' => $service->uuid];
                }
            }
        }

        return $rows;
    }
}
