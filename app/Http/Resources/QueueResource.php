<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QueueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'queue_number' => $this->queue_number,
            'queue_status' => $this->queue_status,
            'called_at' => optional($this->called_at)->toIso8601String(),
            'served_at' => optional($this->served_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'appointment_uuid' => $this->whenLoaded('appointment', fn () => $this->appointment?->uuid),
            'client_name' => $this->whenLoaded('appointment', fn () => $this->appointment?->client
                ? trim("{$this->appointment->client->first_name} {$this->appointment->client->last_name}")
                : null),
            'appointment_time' => $this->whenLoaded('appointment', fn () => $this->appointment?->appointment_time),
        ];
    }
}
