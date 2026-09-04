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
            'name' => $this->serviceVariant->service?->name,
            'description' => $this->serviceVariant->service?->description,
            'duration_minutes' => $this->serviceVariant->duration_minutes,
            'price' => (float) ($this->custom_price ?? $this->serviceVariant->price),
        ];
    }
}
