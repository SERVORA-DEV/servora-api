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
            // Where it stands in the line (changes when a client is skipped); the ticket number never does.
            'line_at' => $this->lineTime()->copy()->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
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
