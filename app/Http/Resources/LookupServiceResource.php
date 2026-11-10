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
            // Done by several staff at once: one part each (empty for a one-person service).
            'parts' => $this->resource->getAttribute('parts') ?? [],
            'name' => $this->serviceVariant->service?->name,
            // For grouping the menu on the New Appointment page.
            'category' => $this->serviceVariant->service?->category,
            // time | procedure | facility: a procedure's duration below is an
            // estimate, a facility-based service needs no therapist.
            'service_type' => \App\Support\ServiceKind::of($this->serviceVariant->service?->service_type),
            'facility_required' => \App\Models\Service::hasFacilityRequirement()
                && \App\Support\ServiceKind::needsFacility($this->serviceVariant->service?->service_type, (bool) $this->serviceVariant->service?->facility_required),
            'description' => $this->serviceVariant->service?->description,
            'duration_minutes' => $this->serviceVariant->duration_minutes,
            'price' => (float) ($this->custom_price ?? $this->serviceVariant->price),
            // On a home visit it costs this instead (null = the price above), and a length only
            // for home visits isn't offered at the spa (\App\Support\HomePrices).
            'home_price' => \App\Support\HomePrices::ready() && $this->serviceVariant->home_price !== null ? (float) $this->serviceVariant->home_price : null,
            'home_only' => \App\Support\HomePrices::homeOnly($this->serviceVariant),
            // The vouchers and discounts that cover this service (set by FrontOfficeLookupService
            // for the front desk's menu; empty anywhere else).
            'promotions' => $this->resource->getAttribute('promotions') ?? [],
        ];
    }
}
