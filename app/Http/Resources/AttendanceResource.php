<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
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
            'staff_uuid' => $this->staff?->uuid,
            'staff_name' => trim($this->staff->first_name . ' ' . $this->staff->last_name),
            'branch_name' => $this->staff?->branch?->branch_name,
            'attendance_date' => $this->attendance_date,
            'status' => $this->status,
            'check_in_at' => $this->check_in_at?->format('H:i'),
            'check_out_at' => $this->check_out_at?->format('H:i'),
            'remarks' => $this->remarks,
        ];
    }
}
