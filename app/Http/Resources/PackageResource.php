<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
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
            'code' => $this->code,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'default_price' => (float) $this->default_price,
            'default_commission_amount' => $this->default_commission_amount !== null ? (float) $this->default_commission_amount : null,
            'loyalty_points' => $this->loyalty_points !== null ? (int) $this->loyalty_points : null,
            'is_active' => (bool) $this->is_active,

            // Line items, with the original (pre-bundle) price so the
            // frontend can show savings the same way NIKA's design did —
            // computed here rather than stored, since it's just a sum of the
            // current service prices × quantity.
            'services' => $this->whenLoaded('packageServiceItems', fn () => $this->packageServiceItems->map(fn ($item) => [
                'service_variant_uuid' => $item->serviceVariant?->uuid,
                'name' => $item->serviceVariant?->service?->name,
                'duration_minutes' => $item->serviceVariant?->duration_minutes,
                'quantity' => $item->quantity,
                'unit_price' => $item->serviceVariant ? (float) $item->serviceVariant->price : 0.0,
            ])),
            'original_price' => $this->whenLoaded('packageServiceItems', fn () => $this->packageServiceItems->sum(
                fn ($item) => $item->serviceVariant ? ((float) $item->serviceVariant->price * $item->quantity) : 0.0
            )),

            // Per-branch availability/price override — see
            // ServiceResource::toArray's identical field for the scoping
            // rationale.
            'branches' => $this->whenLoaded('branchPackages', fn () => $this->branchPackages->map(fn ($bp) => [
                'branch_uuid' => $bp->branch?->uuid,
                'branch_name' => $bp->branch?->branch_name,
                'is_available' => (bool) $bp->is_available,
                'custom_price' => $bp->custom_price !== null ? (float) $bp->custom_price : null,
                'custom_commission' => $bp->custom_commission !== null ? (float) $bp->custom_commission : null,
            ])),

            'created_at' => $this->created_at,
        ];
    }
}
