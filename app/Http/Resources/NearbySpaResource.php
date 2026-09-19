<?php

namespace App\Http\Resources;

use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Backs the unauthenticated GET /spas/nearby endpoint (client mobile app's
// Home screen) — same "only what's safe to show a stranger" rule as
// PublicSpaBusinessResource: no email/phone/description/verification
// internals, just what a browse card needs to display and link out to.
class NearbySpaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'branch_name' => $this->branch_name,
            'formatted_address' => $this->formatted_address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'distance_km' => round((float) $this->distance_km, 2),
            'cover_photo_url' => ImageUploadService::url($this->cover_photo),

            'business' => $this->whenLoaded('business', function () {
                return [
                    'uuid' => $this->business->uuid,
                    'business_name' => $this->business->business_name,
                    // See SpaBusinessResource for why both keys are here.
                    'business_logo' => $this->business->business_logo,
                    'business_logo_url' => ImageUploadService::url($this->business->business_logo),
                ];
            }),
        ];
    }
}
