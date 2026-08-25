<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\TherapistAssignment;
use App\Repository\Business\StaffServiceRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Every method returns ['ok' => bool, 'reason' => ?string] rather than
// throwing, matching this codebase's convention of surfacing domain errors
// as data the Service layer turns into a 422 (see AppointmentService).
//
// There is no per-service scheduled sub-slot in this schema — only one
// appointment_date/appointment_time per appointment. Pre-start checks
// (isStaffAvailable/isFacilityAvailable) therefore compare against the
// appointment's single scheduled window plus its estimated total duration
// (sum of its services' variant durations), not a true per-service slice.
// startService() re-validates with the real-time checks
// (isStaffCurrentlyBusy/isFacilityCurrentlyOccupied) to catch drift from an
// earlier service running long — this two-layer check is what actually
// prevents conflicting double-booking end to end.
class AppointmentAvailabilityService
{
    private StaffServiceRepository $staffServiceRepository;

    public function __construct(StaffServiceRepository $staffServiceRepository)
    {
        $this->staffServiceRepository = $staffServiceRepository;
    }

    public function isStaffQualified(int $staffId, int $serviceId): array
    {
        if ($this->staffServiceRepository->isQualified($staffId, $serviceId)) {
            return ['ok' => true, 'reason' => null];
        }

        return ['ok' => false, 'reason' => 'This therapist is not qualified to perform this service.'];
    }

    // First real consumer of staff_schedules in this codebase. A staff
    // member with no schedule rows at all for the day is treated as
    // available (opt-in-if-configured, same stance as qualification) —
    // most branches won't have shift data entered before this module goes
    // live.
    public function staffMatchesSchedule(int $staffId, string $date, string $startTime, int $durationMinutes): array
    {
        $dayOfWeek = Carbon::parse($date)->format('l');
        $start = Carbon::parse("{$date} {$startTime}");
        $end = (clone $start)->addMinutes($durationMinutes);

        $schedules = StaffSchedule::where('staff_id', $staffId)
            ->where('day_of_week', $dayOfWeek)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->get();

        if ($schedules->isEmpty()) {
            return ['ok' => true, 'reason' => null];
        }

        foreach ($schedules as $schedule) {
            if ($schedule->is_day_off || ! $schedule->start_time || ! $schedule->end_time) {
                continue;
            }

            $shiftStart = Carbon::parse("{$date} {$schedule->start_time}");
            $shiftEnd = Carbon::parse("{$date} {$schedule->end_time}");

            if ($start->lt($shiftStart) || $end->gt($shiftEnd)) {
                continue;
            }

            if ($schedule->break_start && $schedule->break_end) {
                $breakStart = Carbon::parse("{$date} {$schedule->break_start}");
                $breakEnd = Carbon::parse("{$date} {$schedule->break_end}");

                if ($start->lt($breakEnd) && $end->gt($breakStart)) {
                    continue;
                }
            }

            return ['ok' => true, 'reason' => null];
        }

        return ['ok' => false, 'reason' => 'This therapist is not scheduled to work during the requested time.'];
    }

    public function isStaffAvailable(int $staffId, string $date, string $startTime, int $durationMinutes, ?int $excludeAssignmentId = null): array
    {
        $start = Carbon::parse("{$date} {$startTime}");
        $end = (clone $start)->addMinutes($durationMinutes);

        $assignments = TherapistAssignment::with('appointmentService.appointment.services.serviceVariant')
            ->where('staff_id', $staffId)
            ->whereIn('assignment_status', ['Assigned', 'In Progress'])
            ->when($excludeAssignmentId, fn ($q) => $q->where('id', '!=', $excludeAssignmentId))
            ->get();

        foreach ($assignments as $assignment) {
            if ($this->overlapsAppointment($assignment, $date, $start, $end)) {
                return ['ok' => false, 'reason' => 'This therapist is already booked during the requested time.'];
            }
        }

        return ['ok' => true, 'reason' => null];
    }

    public function isFacilityAvailable(int $facilityId, string $date, string $startTime, int $durationMinutes, ?int $excludeAssignmentId = null): array
    {
        $start = Carbon::parse("{$date} {$startTime}");
        $end = (clone $start)->addMinutes($durationMinutes);

        $assignments = TherapistAssignment::with('appointmentService.appointment.services.serviceVariant')
            ->where('facility_id', $facilityId)
            ->whereIn('assignment_status', ['Assigned', 'In Progress'])
            ->when($excludeAssignmentId, fn ($q) => $q->where('id', '!=', $excludeAssignmentId))
            ->get();

        foreach ($assignments as $assignment) {
            if ($this->overlapsAppointment($assignment, $date, $start, $end)) {
                return ['ok' => false, 'reason' => 'This room is already booked during the requested time.'];
            }
        }

        return ['ok' => true, 'reason' => null];
    }

    // Real-time check (not schedule-window based) — used at startService()
    // time to catch drift when an earlier service on this staff member ran
    // long past its estimated duration.
    public function isStaffCurrentlyBusy(int $staffId, ?int $excludeAssignmentId = null): array
    {
        $busy = TherapistAssignment::where('staff_id', $staffId)
            ->where('assignment_status', 'In Progress')
            ->when($excludeAssignmentId, fn ($q) => $q->where('id', '!=', $excludeAssignmentId))
            ->exists();

        return $busy
            ? ['ok' => false, 'reason' => 'This therapist is currently in another service.']
            : ['ok' => true, 'reason' => null];
    }

    public function isFacilityCurrentlyOccupied(int $facilityId, ?int $excludeAssignmentId = null): array
    {
        $occupied = TherapistAssignment::where('facility_id', $facilityId)
            ->where('assignment_status', 'In Progress')
            ->when($excludeAssignmentId, fn ($q) => $q->where('id', '!=', $excludeAssignmentId))
            ->exists();

        return $occupied
            ? ['ok' => false, 'reason' => 'This room is currently occupied by another service.']
            : ['ok' => true, 'reason' => null];
    }

    public function suggestTherapists(int $branchId, int $serviceId, string $date, string $startTime, int $durationMinutes): Collection
    {
        return Staff::where('spa_branch_id', $branchId)
            ->where('role', 'therapist')
            ->where('status', 'active')
            ->get()
            ->filter(function (Staff $staff) use ($serviceId, $date, $startTime, $durationMinutes) {
                return $this->isStaffQualified($staff->id, $serviceId)['ok']
                    && $this->staffMatchesSchedule($staff->id, $date, $startTime, $durationMinutes)['ok']
                    && $this->isStaffAvailable($staff->id, $date, $startTime, $durationMinutes)['ok'];
            })
            ->values();
    }

    private function overlapsAppointment(TherapistAssignment $assignment, string $date, Carbon $start, Carbon $end): bool
    {
        $appointment = $assignment->appointmentService?->appointment;

        if (! $appointment || $appointment->appointment_date->format('Y-m-d') !== $date) {
            return false;
        }

        if (in_array($appointment->status, ['Cancelled', 'No Show', 'Completed'], true)) {
            return false;
        }

        $otherStart = Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->appointment_time);
        $otherEnd = (clone $otherStart)->addMinutes($this->estimatedDurationMinutes($appointment));

        return $start->lt($otherEnd) && $end->gt($otherStart);
    }

    // Sum of duration_minutes across this appointment's non-cancelled
    // services — the "estimated total duration" used as the scheduled
    // window's length, since there's no per-service scheduled sub-slot.
    // Falls back to 30 minutes per service if a variant relation somehow
    // failed to load, so a data gap never collapses the window to zero.
    private function estimatedDurationMinutes(Appointment $appointment): int
    {
        return $appointment->services
            ->where('status', '!=', 'Cancelled')
            ->sum(fn ($service) => $service->serviceVariant?->duration_minutes ?? 30);
    }
}
