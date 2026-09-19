<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Backs the unauthenticated GET /spas/{uuid}/therapists availability
// lookup. Wraps the ['staff' => Staff, 'available' => bool, 'reason' =>
// ?string] rows SpaBranchService::publicTherapistAvailability() builds.
//
// Same "only what's safe to show a stranger" rule as BranchDetailResource:
// the identity fields match what that endpoint's 'therapists' key already
// exposes, and the reason is deliberately coarse — the business-side
// AppointmentService::therapistOptions() returns shift times and check-in
// state, none of which a prospective client has any business seeing.
class PublicTherapistAvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $this->resource['staff'];

        return [
            'uuid' => $staff->uuid,
            'first_name' => $staff->first_name,
            'last_name' => $staff->last_name,
            'role' => $staff->role,
            'available' => (bool) $this->resource['available'],
            'reason' => $this->resource['reason'],
        ];
    }
}
