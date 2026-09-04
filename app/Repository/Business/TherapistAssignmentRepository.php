<?php

namespace App\Repository\Business;

use App\Models\TherapistAssignment;

class TherapistAssignmentRepository
{
    // Reactivates a previously-Cancelled row instead of inserting a
    // duplicate — this is what makes the
    // unique(appointment_service_id, staff_id) constraint safe to have
    // while still allowing "cancel then reassign the same therapist" to
    // work.
    public function assign(int $appointmentServiceId, int $staffId, ?int $facilityId = null): TherapistAssignment
    {
        return TherapistAssignment::updateOrCreate(
            ['appointment_service_id' => $appointmentServiceId, 'staff_id' => $staffId],
            [
                'facility_id' => $facilityId,
                'assignment_status' => 'Assigned',
                'assigned_at' => now(),
                'started_at' => null,
                'completed_at' => null,
            ]
        );
    }

    // A room reserved before any therapist was picked (staff_id IS NULL) —
    // used by assignTherapist() to "claim" that same row with a staff
    // member instead of creating a second, duplicate assignment for the
    // same service.
    public function findUnclaimedForService(int $appointmentServiceId): ?TherapistAssignment
    {
        return TherapistAssignment::where('appointment_service_id', $appointmentServiceId)
            ->whereNull('staff_id')
            ->where('assignment_status', 'Assigned')
            ->first();
    }

    // Turns a room-only row into a real staff assignment in place, so the
    // room already on it isn't lost or duplicated.
    public function claim(TherapistAssignment $assignment, int $staffId): TherapistAssignment
    {
        $assignment->update(['staff_id' => $staffId, 'assigned_at' => now()]);
        return $assignment;
    }

    // Reserves a room for a service with no therapist yet. Keyed on
    // staff_id => null so MySQL's NULL-distinct unique-index semantics
    // (unique(appointment_service_id, staff_id)) never collide across
    // services, and so this reuses the same reactivate-on-cancel idiom as
    // assign() above.
    public function assignRoomOnly(int $appointmentServiceId, int $facilityId): TherapistAssignment
    {
        return TherapistAssignment::updateOrCreate(
            ['appointment_service_id' => $appointmentServiceId, 'staff_id' => null],
            [
                'facility_id' => $facilityId,
                'assignment_status' => 'Assigned',
                'assigned_at' => now(),
                'started_at' => null,
                'completed_at' => null,
            ]
        );
    }

    public function findByUuidForService(string $uuid, int $appointmentServiceId)
    {
        return TherapistAssignment::where('uuid', $uuid)
            ->where('appointment_service_id', $appointmentServiceId)
            ->firstOrFail();
    }

    public function findByUuid(string $uuid)
    {
        return TherapistAssignment::where('uuid', $uuid)->firstOrFail();
    }

    public function cancel(TherapistAssignment $assignment): TherapistAssignment
    {
        $assignment->update(['assignment_status' => 'Cancelled']);
        return $assignment;
    }

    public function assignFacility(TherapistAssignment $assignment, int $facilityId): TherapistAssignment
    {
        $assignment->update(['facility_id' => $facilityId]);
        return $assignment;
    }
}
