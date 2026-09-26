<?php

namespace App\Http\Resources\Client;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'appointment_uuid' => $this->type === 'Appointment' ? $this->reference_uuid : null,
            'is_read' => (bool) $this->is_read,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
