<?php

namespace App\Http\Resources;

use App\Support\BusinessActivityDescriber;
use App\Support\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// One row of Business → Audit Log. Everything shown is the row's own copy
// (the names as they were); see RecordBusinessActivity.
class BusinessAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'action' => $this->action,
            'event' => $this->event,
            'area' => $this->area,
            'summary' => $this->summary,
            'subject' => $this->subject_label,
            'actor' => [
                'id' => $this->user?->uuid,
                'name' => $this->actor_name,
                'role' => $this->actor_role,
                'level' => $this->actor_level,
            ],
            'branch' => $this->branch_name,
            // Cleaned again on the way out, for rows a service wrote itself.
            'old_values' => $this->old_values ? BusinessActivityDescriber::clean($this->old_values) : null,
            'new_values' => $this->new_values ? BusinessActivityDescriber::clean($this->new_values) : null,
            'device' => $this->user_agent ? UserAgentParser::describe($this->user_agent) : null,
            'device_type' => UserAgentParser::deviceType($this->user_agent),
            'ip_address' => $this->ip_address,
            'link' => $this->link,
        ];
    }
}
