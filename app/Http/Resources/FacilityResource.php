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
            'type' => $this->type,
            'capacity' => $this->capacity,
            'status' => $this->status,
            'is_available' => (bool) $this->is_available,
            'amenities' => $this->amenities ?? [],
            'spa_branch_uuid' => $this->branch?->uuid,
            'branch_name' => $this->branch?->branch_name,

            'created_at' => $this->created_at,
        ];
    }
}
