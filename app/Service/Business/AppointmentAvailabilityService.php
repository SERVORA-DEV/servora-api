<?php

namespace App\Service\Business;

use App\Models\Appointment;
use App\Models\BranchSchedule;
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
// appointment_date/appointment_time per appointment, and this is a
// walk-in/queue-driven spa, not a fixed-slot scheduler: real availability
// depends on when the current service actually finishes and where a client
// sits in the queue, neither of which a projected time-window can predict.
// So isStaffAvailable() (below) is NOT used to gate reservations anywhere —
// AppointmentService's assign*/reschedule methods let staff reserve a
// therapist ahead of time unconditionally. Its only remaining consumer is
// suggestTherapists(), where an estimated overlap is just a soft ranking
// signal, never a hard block. Rooms are the one exception: a physical room
// genuinely can't hold two appointments at once, so isFacilityAvailable()
// (below) IS enforced as a hard gate in AppointmentService's room-assignment
// methods, unlike its staff counterpart. Beyond that, the one real
// real-time conflict check left in the system is the pair
// isStaffCurrentlyBusy()/isFacilityCurrentlyOccupied(), enforced once, at
// startService() — a service can't start on a resource that's genuinely In
// Progress elsewhere right now.
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

        $sawDayOff = false;

        foreach ($schedules as $schedule) {
            if ($schedule->is_day_off) {
                $sawDayOff = true;
                continue;
            }

            if (! $schedule->start_time || ! $schedule->end_time) {
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

        // Distinguishes "explicitly not working today" from "just doesn't fit
        // this window" — AppointmentService::therapistOptions() relies on
        // this same day-off signal (via AttendanceStatusCalculator) to hard-
        // block picking a day-off therapist even if they're checked in; this
        // gives the explicit Assign action (assignTherapistInternal) the
        // matching, clearer rejection message rather than the generic one.
        return [
            'ok' => false,
            'reason' => $sawDayOff
                ? 'This therapist is on their day off today and cannot be assigned.'
                : 'This therapist is not scheduled to work during the requested time.',
        ];
    }

    // Branch-hours equivalent of staffMatchesSchedule() above — gates
    // whether the appointment itself can be booked at all, independent of
    // which staff/service is involved, so it's checked once per
    // appointment rather than per therapist. Same "not configured = open"
    // stance as staff schedules: a branch with no schedule row for that
    // day, or a row with either time left blank, is treated as open rather
    // than rejecting bookings for data the owner hasn't entered yet.
    //
    // There is no separate "open 24 hours" flag in the schema — two
    // equal same-day times (e.g. 00:00-00:00) is the only way to express
    // it, so that's treated as open all day. Beyond that, this follows
    // BranchScheduleRequest's documented convention: closing_time <=
    // opening_time means the branch closes the following day (e.g. open
    // 09:00, close 00:00 = closes at midnight; open 22:00, close 02:00 =
    // open past midnight) rather than being an invalid/never-open range.
    public function branchIsOpen(int $branchId, string $date, string $startTime): array
    {
        $dayOfWeek = Carbon::parse($date)->format('l');

        $schedule = BranchSchedule::where('spa_branch_id', $branchId)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if (! $schedule) {
            return ['ok' => true, 'reason' => null];
        }

        if ($schedule->is_closed) {
            return ['ok' => false, 'reason' => 'The branch is closed on this day.'];
        }

        if (! $schedule->opening_time || ! $schedule->closing_time) {
            return ['ok' => true, 'reason' => null];
        }

        $open = Carbon::parse("{$date} {$schedule->opening_time}");
        $close = Carbon::parse("{$date} {$schedule->closing_time}");

        if ($open->eq($close)) {
            return ['ok' => true, 'reason' => null];
        }

        $time = Carbon::parse("{$date} {$startTime}");

        $inWindow = $close->gt($open)
            ? $time->gte($open) && $time->lt($close)
            : $time->gte($open) || $time->lt($close);

        if (! $inWindow) {
            return ['ok' => false, 'reason' => 'The requested time is outside the branch\'s operating hours.'];
        }

        if ($schedule->break_start && $schedule->break_end) {
            $breakStart = Carbon::parse("{$date} {$schedule->break_start}");
            $breakEnd = Carbon::parse("{$date} {$schedule->break_end}");

            $inBreak = $breakEnd->gt($breakStart)
                ? $time->gte($breakStart) && $time->lt($breakEnd)
                : $time->gte($breakStart) || $time->lt($breakEnd);

            if ($inBreak) {
                return ['ok' => false, 'reason' => 'The requested time falls within the branch\'s break period.'];
            }
        }

        return ['ok' => true, 'reason' => null];
    }

    // Soft signal only — used by suggestTherapists() to rank booking-time
    // candidates, never to block a reservation (see the class doc comment).
    public function isStaffAvailable(int $staffId, string $date, string $startTime, int $durationMinutes): array
    {
        $start = Carbon::parse("{$date} {$startTime}");
        $end = (clone $start)->addMinutes($durationMinutes);

        $assignments = TherapistAssignment::with('appointmentService.appointment.services.serviceVariant')
            ->where('staff_id', $staffId)
            ->whereIn('assignment_status', ['Assigned', 'In Progress'])
            ->get();

        foreach ($assignments as $assignment) {
            if ($this->overlapsAppointment($assignment, $date, $start, $end)) {
                return ['ok' => false, 'reason' => 'This therapist is already booked during the requested time.'];
            }
        }

        return ['ok' => true, 'reason' => null];
    }

    // Backs the front-office time picker's "grey out already-booked times."
    // Branch-wide rather than per-therapist: a therapist is optional at
    // booking time and usually assigned later at check-in, so most
    // appointments have no TherapistAssignment yet to key a per-staff check
    // off of — branch-wide is what actually catches the common case. Uses
    // the same appointment-total-duration math as overlapsAppointment() (no
    // per-service sub-slot in this schema), falling back to a 30-minute
    // window for an appointment with no services yet so it still occupies a
    // real slot instead of collapsing to zero width.
    public function busyWindowsForBranch(int $branchId, string $date): array
    {
        $appointments = Appointment::where('spa_branch_id', $branchId)
            ->where('appointment_date', $date)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW, Appointment::STATUS_COMPLETED])
            ->with('services.serviceVariant')
            ->get();

        return $appointments->map(function (Appointment $appointment) {
            $start = Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->appointment_time);
            $duration = max($this->estimatedDurationMinutes($appointment), 30);
            $end = (clone $start)->addMinutes($duration);
            return ['start' => $start->format('H:i'), 'end' => $end->format('H:i')];
        })->values()->all();
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

    // Hard gate, unlike isStaffAvailable() above — a room is a single
    // physical resource that can't serve two appointments at once, so
    // unlike therapist scheduling (soft/advisory only, see class doc
    // comment), room booking IS time-blocked: this is called at
    // assignment time in AppointmentService and actually rejects a
    // conflicting request rather than just ranking candidates.
    //
    // $excludeAppointmentId excludes the room's OTHER assignments on this
    // SAME appointment — a single visit's several services (e.g. a facial
    // then a massage) all share one appointment-level date/time in this
    // schema (no per-service sub-slots), so they'd trivially "overlap"
    // themselves. Reusing the same room across one client's own services is
    // the normal case, not a conflict; only a genuinely different
    // appointment competing for the same room at an overlapping time is.
    public function isFacilityAvailable(int $facilityId, string $date, string $startTime, int $durationMinutes, ?int $excludeAssignmentId = null, ?int $excludeAppointmentId = null): array
    {
        $start = Carbon::parse("{$date} {$startTime}");
        $end = (clone $start)->addMinutes($durationMinutes);

        $assignments = TherapistAssignment::with('appointmentService.appointment.services.serviceVariant')
            ->where('facility_id', $facilityId)
            ->whereIn('assignment_status', ['Assigned', 'In Progress'])
            ->when($excludeAssignmentId, fn ($q) => $q->where('id', '!=', $excludeAssignmentId))
            ->get();

        foreach ($assignments as $assignment) {
            $otherAppointmentId = $assignment->appointmentService?->appointment?->id;
            if ($excludeAppointmentId && $otherAppointmentId === $excludeAppointmentId) {
                continue;
            }
            if ($this->overlapsAppointment($assignment, $date, $start, $end)) {
                return ['ok' => false, 'reason' => 'This room is already booked during the requested time.'];
            }
        }

        return ['ok' => true, 'reason' => null];
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

        if (in_array($appointment->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW, Appointment::STATUS_COMPLETED], true)) {
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
    // Public because AppointmentResource reuses this exact sum as the
    // client-facing "total duration" figure rather than re-deriving it.
    public function estimatedDurationMinutes(Appointment $appointment): int
    {
        return $appointment->services
            ->where('status', '!=', 'Cancelled')
            ->sum(fn ($service) => $service->serviceVariant?->duration_minutes ?? 30);
    }

    // Same duration math, but only services still outstanding (Pending or
    // In Progress) — "how much service time is actually left," unlike
    // estimatedDurationMinutes()'s whole-visit total (which deliberately
    // keeps counting Completed services for booking-conflict math and the
    // Rooms/Therapist tabs' "Total duration" label — that figure stays
    // as-is; this is a separate, narrower one for "Est. service time").
    public function remainingDurationMinutes(Appointment $appointment): int
    {
        return $appointment->services
            ->whereIn('status', ['Pending', 'In Progress'])
            ->sum(fn ($service) => $service->serviceVariant?->duration_minutes ?? 30);
    }
}
