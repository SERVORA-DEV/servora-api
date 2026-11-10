<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceVariantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'duration_minutes' => $this->duration_minutes,
            'price' => (float) $this->price,
            // Home service (\App\Support\HomePrices): its price at the client's home (null = the price above)
            // and whether it is offered only for home visits.
            'home_price' => \App\Support\HomePrices::ready() && $this->home_price !== null ? (float) $this->home_price : null,
            'home_only' => \App\Support\HomePrices::homeOnly($this->resource),
            // Not the variant's own concept — a variant is just a duration/
            // price option of its Service, so its status always mirrors the
            // parent's is_active (see ServiceResource, which sets this
            // relation on every variant it loads to avoid an N+1 query here).
            'is_active' => (bool) $this->service->is_active,
            'home_service_available' => (bool) $this->service->home_service_available,

            // Per-branch availability/price override — scoped to the
            // requesting user's own accessible branches by the repository's
            // eager load, not here, so a manager never sees a sibling
            // branch's row.
            'branches' => $this->whenLoaded('branchServices', fn () => $this->branchServices->map(fn ($bs) => [
                'branch_uuid' => $bs->branch?->uuid,
                'branch_name' => $bs->branch?->branch_name,
                'is_available' => (bool) $bs->is_available,
                'custom_price' => $bs->custom_price !== null ? (float) $bs->custom_price : null,
            ])),
        ];
    }
}
