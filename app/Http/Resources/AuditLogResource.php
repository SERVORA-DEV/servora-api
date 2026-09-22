<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // user is eager-loaded (see AuditLogRepository::paginate) — nullable
        // because user_id is set-null-on-delete, and some log rows are
        // system-generated with no acting user at all.
        return [
            'id' => $this->id,
            'actor_first_name' => $this->user?->first_name,
            'actor_last_name' => $this->user?->last_name,
            'actor_email' => $this->user?->email,
            'table_name' => $this->table_name,
            'record_id' => $this->record_id,
            'action' => $this->action,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
