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
        ];
    }
}
