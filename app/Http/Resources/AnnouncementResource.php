<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// One row of the announcement history. read_count is how many recipients have
// opened their in-app copy (null when it was not counted for this response).
class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sender = $this->sender;

        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'message' => $this->message,
            'audience' => $this->audience,
            'scope' => $this->scope,
            'priority' => $this->priority,
            'status' => $this->status,
            'recipient_count' => $this->recipient_count,
            'read_count' => $this->read_count ?? null,
            'sender' => $sender ? trim("{$sender->first_name} {$sender->last_name}") : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
