<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'default_price' => (float) $this->default_price,
            'default_commission_percentage' => $this->default_commission_percentage !== null ? (float) $this->default_commission_percentage : null,
            'is_active' => (bool) $this->is_active,

            // Per-branch availability/price override — scoped to the
            // requesting user's own accessible branches by the repository's
            // eager load (see ServiceRepository::paginateForBusiness), not
            // here, so a manager never sees a sibling branch's row.
            'branches' => $this->whenLoaded('branchServices', fn () => $this->branchServices->map(fn ($bs) => [
                'branch_uuid' => $bs->branch?->uuid,
                'branch_name' => $bs->branch?->branch_name,
                'is_available' => (bool) $bs->is_available,
                'custom_price' => $bs->custom_price !== null ? (float) $bs->custom_price : null,
            ])),

            'created_at' => $this->created_at,
        ];
    }
}
