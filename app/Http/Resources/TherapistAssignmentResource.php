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
            'staff_name' => $this->staff ? trim("{$this->staff->first_name} {$this->staff->last_name}") : null,
            'facility_uuid' => $this->facility?->uuid,
            'facility_name' => $this->facility?->name,
            'assignment_status' => $this->assignment_status,
            'assigned_at' => optional($this->assigned_at)->toIso8601String(),
            'started_at' => optional($this->started_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'remarks' => $this->remarks,
        ];
    }
}
