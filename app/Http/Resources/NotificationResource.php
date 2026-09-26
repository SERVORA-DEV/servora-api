<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Shape matches the frontend's RawSystemNotification exactly (see
// types/system-notification.ts on servora-web).
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'is_read' => $this->is_read,
            'read_at' => $this->read_at,
            'reference_uuid' => $this->reference_uuid,
            'created_at' => $this->created_at,
        ];
    }
}
