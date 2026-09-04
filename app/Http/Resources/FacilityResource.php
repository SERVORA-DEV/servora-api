<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacilityResource extends JsonResource
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
            'category' => $this->category,
            // 'Occupied' overrides the stored status (Available/Maintenance)
            // whenever there's a live in-progress assignment; is_available
            // false (Inactive) takes precedence over both — a room that's
            // off the books isn't "occupied" or "under maintenance", it's
            // just not in use at all. is_occupied comes from the
            // withExists() alias in FacilityRepository.
            'status' => ! $this->is_available ? 'Inactive' : ($this->is_occupied ? 'Occupied' : $this->status),
            'is_available' => (bool) $this->is_available,
            'amenities' => $this->amenities ?? [],
            'services' => $this->whenLoaded('services', fn () => $this->services->map(fn ($s) => [
                'uuid' => $s->uuid,
                'name' => $s->name,
            ])),
            'spa_branch_uuid' => $this->branch?->uuid,
            'branch_name' => $this->branch?->branch_name,

            'created_at' => $this->created_at,
        ];
    }
}
