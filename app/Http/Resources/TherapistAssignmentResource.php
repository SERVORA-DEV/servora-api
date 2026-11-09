<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TherapistAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'staff_uuid' => $this->staff?->uuid,
            // Names as they were when assigned (the row's own copy).
            'staff_name' => $this->resource->staffName(),
            'facility_uuid' => $this->facility?->uuid,
            'facility_name' => $this->resource->roomName(),
            // A service done by several staff at once: which part this person does, and its share
            // of the commission (percent) as it was when they were put on it. Null otherwise.
            'part_uuid' => $this->relationLoaded('part') ? $this->part?->uuid : null,
            'part_name' => $this->part_name,
            'commission_share' => $this->commission_share !== null ? (float) $this->commission_share : null,
            'assignment_status' => $this->assignment_status,
            'assigned_at' => optional($this->assigned_at)->toIso8601String(),
            'started_at' => optional($this->started_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'remarks' => $this->remarks,
        ];
    }
}
