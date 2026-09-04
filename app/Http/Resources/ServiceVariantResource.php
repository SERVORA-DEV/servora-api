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
            'commission_amount' => $this->commission_amount !== null ? (float) $this->commission_amount : null,
            'loyalty_points' => $this->loyalty_points !== null ? (int) $this->loyalty_points : null,
            'is_active' => (bool) $this->is_active,

            // Per-branch availability/price override — scoped to the
            // requesting user's own accessible branches by the repository's
            // eager load, not here, so a manager never sees a sibling
            // branch's row.
            'branches' => $this->whenLoaded('branchServices', fn () => $this->branchServices->map(fn ($bs) => [
                'branch_uuid' => $bs->branch?->uuid,
                'branch_name' => $bs->branch?->branch_name,
                'is_available' => (bool) $bs->is_available,
                'custom_price' => $bs->custom_price !== null ? (float) $bs->custom_price : null,
                'custom_commission' => $bs->custom_commission !== null ? (float) $bs->custom_commission : null,
            ])),
        ];
    }
}
