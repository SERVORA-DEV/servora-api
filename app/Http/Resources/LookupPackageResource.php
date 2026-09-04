<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Wraps a BranchPackage — uuid exposed is the Package's (what
// AppointmentService::addPackage expects as package_uuid), price is this
// branch's effective price (custom_price ?? the package's own default_price).
// Mirrors LookupServiceResource.
class LookupPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->package->uuid,
            'name' => $this->package->name,
            'duration_minutes' => $this->package->duration_minutes,
            'price' => (float) ($this->custom_price ?? $this->package->default_price),
        ];
    }
}
