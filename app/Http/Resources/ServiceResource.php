<?php

namespace App\Http\Resources;

use App\Services\ImageUploadService;
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
            'code' => $this->code,
            'description' => $this->description,
            'image_path' => $this->image_path,
            'image_url' => ImageUploadService::url($this->image_path),
            'is_active' => (bool) $this->is_active,

            // Each bookable duration/price/commission/points option — see
            // ServiceVariantResource. Always loaded by the repository
            // (unlike its nested `branches`, which stays optional).
            'variants' => $this->whenLoaded('variants', fn () => ServiceVariantResource::collection($this->variants)),

            'created_at' => $this->created_at,
        ];
    }
}
