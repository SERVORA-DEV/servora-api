<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// An add-on as the owner's Add-ons tab and the front desk's picker read it.
class AddonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'price' => (float) $this->price,
            // What it adds to its main service's time (0 = none).
            'duration_minutes' => (int) $this->duration_minutes,
            // Where it is available (App\Support\ServiceAddon): these service
            // categories — every service in them, or only service_uuids.
            'categories' => array_values((array) ($this->categories ?? [])),
            'all_services' => (bool) $this->all_services,
            'service_uuids' => $this->all_services || ! $this->relationLoaded('services')
                ? []
                : $this->services->pluck('uuid')->values(),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
