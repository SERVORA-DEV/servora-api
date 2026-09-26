<?php

namespace App\Http\Resources\Client;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// A review as its own author sees it (My Reviews, and nested on their
// booking). The spa context is only included when the appointment's branch
// is loaded — nested under a booking it's redundant.
class ClientReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $appointment = $this->relationLoaded('appointment') ? $this->appointment : null;
        $branch = $appointment && $appointment->relationLoaded('branch') ? $appointment->branch : null;

        return [
            'uuid' => $this->uuid,
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'is_anonymous' => (bool) $this->is_anonymous,
            'status' => $this->status,
            'reviewed_at' => optional($this->reviewed_at ?? $this->created_at)->toIso8601String(),
            'appointment_uuid' => $appointment?->uuid,
            'appointment_date' => $appointment ? optional($appointment->appointment_date)->format('Y-m-d') : null,
            'services' => $appointment && $appointment->relationLoaded('services')
                ? $appointment->services->map(fn ($s) => $s->serviceVariant?->service?->name)->filter()->unique()->values()
                : [],
            'branch' => $branch ? [
                'uuid' => $branch->uuid,
                'name' => $branch->branch_name,
                'business_name' => $branch->business?->business_name,
                'cover_photo_url' => $branch->coverPhotoUrl(),
            ] : null,
        ];
    }
}
