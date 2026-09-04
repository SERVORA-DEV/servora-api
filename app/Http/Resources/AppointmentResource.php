<?php

namespace App\Http\Resources;

use App\Service\Business\AppointmentAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'appointment_number' => $this->appointment_number,
            'appointment_date' => optional($this->appointment_date)->format('Y-m-d'),
            'appointment_time' => $this->appointment_time,
            'appointment_type' => $this->appointment_type,
            'source' => $this->source,
            'status' => $this->status,
            'check_in_at' => optional($this->check_in_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'remarks' => $this->remarks,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'total_amount' => (float) $this->total_amount,
            // Reuses AppointmentAvailabilityService's own overlap-checking
            // math (sum of non-cancelled services' variant durations) so
            // this figure never drifts from what booking conflicts are
            // actually checked against.
            'total_duration_minutes' => $this->when(
                $this->relationLoaded('services'),
                fn () => app(AppointmentAvailabilityService::class)->estimatedDurationMinutes($this->resource)
            ),
            // Narrower sibling of total_duration_minutes — only services
            // still Pending/In Progress, for "how much is actually left"
            // displays (see AppointmentAvailabilityService::remainingDurationMinutes).
            'remaining_duration_minutes' => $this->when(
                $this->relationLoaded('services'),
                fn () => app(AppointmentAvailabilityService::class)->remainingDurationMinutes($this->resource)
            ),

            'client' => new ClientResource($this->whenLoaded('client')),
            'branch_uuid' => $this->whenLoaded('branch', fn () => $this->branch?->uuid),
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->branch_name),

            'services' => AppointmentServiceResource::collection($this->whenLoaded('services')),
            'packages' => AppointmentPackageResource::collection($this->whenLoaded('packages')),
            'queue' => new QueueResource($this->whenLoaded('queue')),
            'billing' => new BillingResource($this->whenLoaded('billing')),

            'created_at' => $this->created_at,
        ];
    }
}
