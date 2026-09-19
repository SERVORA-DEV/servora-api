<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Narrow projection of Staff for the front-office therapist picker —
// front_officer has no staff_view permission, so this deliberately exposes
// only what's needed to pick a therapist, not the full StaffResource.
class LookupTherapistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => trim("{$this->first_name} {$this->last_name}"),

            // Which services this therapist may perform, so the new-appointment
            // modal can flag an ineligible "Requested Therapist" while the
            // front officer is still filling the form instead of leaving it to
            // a warning on the created appointment. Service-level uuids (not
            // variant uuids) — see the staff_services migration.
            //
            // An EMPTY array means unrestricted, not "can perform nothing"
            // (StaffServiceRepository::isQualified's opt-in-if-configured
            // rule). Any consumer filtering on this has to special-case it.
            'qualified_service_uuids' => $this->qualifiedServices->pluck('uuid'),
        ];
    }
}
