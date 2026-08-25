<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'service_variant_uuid' => $this->serviceVariant?->uuid,
            'service_name' => $this->serviceVariant?->service?->name,
            'duration_minutes' => $this->serviceVariant?->duration_minutes,
            'quantity' => (int) $this->quantity,
            'sort_order' => (int) $this->sort_order,
            'unit_price' => (float) $this->unit_price,
            'discount_amount' => (float) $this->discount_amount,
            'subtotal' => (float) $this->subtotal,
            'points_earned' => (int) $this->points_earned,
            'status' => $this->status,
            'notes' => $this->notes,
            'source_package_uuid' => $this->sourceAppointmentPackage?->uuid,
            'source_package_name' => $this->sourceAppointmentPackage?->package?->name,
            'therapist_assignments' => TherapistAssignmentResource::collection($this->whenLoaded('therapistAssignments')),
        ];
    }
}
