<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffScheduleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'day_of_week' => $this->day_of_week,
            'is_day_off' => (bool) $this->is_day_off,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'break_start' => $this->break_start,
            'break_end' => $this->break_end,
        ];
    }
}
