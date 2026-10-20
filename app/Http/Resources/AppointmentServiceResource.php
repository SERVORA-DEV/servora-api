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
            // The service itself (rooms and therapist skills are set per
            // service, not per length).
            'service_uuid' => $this->serviceVariant?->service?->uuid,
            // Name, type and length are the line's own copy of what was
            // booked (AppointmentServiceItem / App\Support\History).
            'service_name' => $this->resource->displayName(),
            // time | procedure | facility (App\Support\ServiceKind).
            'service_type' => $this->resource->kind(),
            // A facility has to be chosen before it can start (the booking's
            // own copy — AppointmentServiceItem::needsFacility).
            'facility_required' => $this->resource->needsFacility(),
            'duration_minutes' => $this->resource->lengthMinutes(),
            'quantity' => (int) $this->quantity,
            'sort_order' => (int) $this->sort_order,
            'unit_price' => (float) $this->unit_price,
            'discount_amount' => (float) $this->discount_amount,
            'subtotal' => (float) $this->subtotal,
            // A package service that was extended: what that adds on top of
            // the package price.
            'extra_charge' => (float) ($this->extra_charge ?? 0),
            'points_earned' => (int) $this->points_earned,
            'status' => $this->status,
            'notes' => $this->notes,
            // An add-on: the main service it rides with (AppointmentResource
            // hands each line its parent, so this asks nothing more).
            'parent_uuid' => $this->parent_appointment_service_id ? $this->resource->parent?->uuid : null,
            // …and which add-on it is (App\Models\Addon).
            'addon_uuid' => $this->addon_id ? $this->resource->addon?->uuid : null,
            'source_package_uuid' => $this->sourceAppointmentPackage?->uuid,
            'source_package_name' => $this->sourceAppointmentPackage?->displayName(),
            'therapist_assignments' => TherapistAssignmentResource::collection($this->whenLoaded('therapistAssignments')),
        ];
    }
}
