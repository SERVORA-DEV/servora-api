<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Wraps a BranchService — uuid exposed is the ServiceVariant's (what
// appointment_services.service_variant_id actually points to), price is
// this branch's effective price (custom_price ?? the variant's own price).
class LookupServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->serviceVariant->uuid,
            // The parent Service's uuid, alongside the variant's above:
            // therapist qualifications are held at the Service level, so the
            // picker needs this to match a chosen variant against
            // LookupTherapistResource's qualified_service_uuids.
            'service_uuid' => $this->serviceVariant->service?->uuid,
            // The owner's "can be done at a client's home" tick.
            'home_service_available' => (bool) $this->serviceVariant->service?->home_service_available,
            'name' => $this->serviceVariant->service?->name,
            // For grouping the menu on the New Appointment page.
            'category' => $this->serviceVariant->service?->category,
            // time | procedure | facility: a procedure's duration below is an
            // estimate, a facility-based service needs no therapist.
            'service_type' => \App\Support\ServiceKind::of($this->serviceVariant->service?->service_type),
            'description' => $this->serviceVariant->service?->description,
            'duration_minutes' => $this->serviceVariant->duration_minutes,
            'price' => (float) ($this->custom_price ?? $this->serviceVariant->price),
        ];
    }
}
