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
