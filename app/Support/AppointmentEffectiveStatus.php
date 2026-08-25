<?php

namespace App\Support;

use App\Models\Appointment;

// Whether a checked-in appointment is "waiting" or "in service" is
// deliberately computed rather than stored — appointment_services.status
// already carries that truth per service, and a stored header value would
// risk drifting out of sync every time a service starts/completes
// independently. Mirrors the OwnerVerificationStatus precedent for derived
// state. Used by AppointmentResource.
class AppointmentEffectiveStatus
{
    public static function compute(Appointment $appointment): string
    {
        if ($appointment->status !== 'Checked In') {
            return match ($appointment->status) {
                'Pending' => 'pending',
                'Confirmed' => 'confirmed',
                'Completed' => 'completed',
                'Cancelled' => 'cancelled',
                'No Show' => 'no_show',
                default => $appointment->status ? strtolower(str_replace(' ', '_', $appointment->status)) : 'pending',
            };
        }

        $inService = $appointment->services->contains(fn ($service) => $service->status === 'In Progress');

        return $inService ? 'checked_in_in_service' : 'checked_in_waiting';
    }
}
