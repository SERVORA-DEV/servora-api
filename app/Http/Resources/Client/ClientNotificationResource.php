<?php

namespace App\Http\Resources\Client;

use App\Support\ClientNotificationLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// `kind` and `business_uuid` say what tapping it opens in the app
// (ClientNotificationLink). A list looks every rewards notice's business up
// at once and hands it in as `link_business_uuid`
// (ClientAccountService::notifications); a single one looks its own up.
class ClientNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $kind = ClientNotificationLink::kind($this->resource);
        // '' = looked up with the list, and none found.
        $linked = $this->resource->getAttribute('link_business_uuid');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'kind' => $kind,
            'appointment_uuid' => in_array($this->type, ['Appointment', 'Payment'], true) ? $this->reference_uuid : null,
            'business_uuid' => $kind === 'reward'
                ? ($linked === null ? ClientNotificationLink::businessUuid($this->resource) : ($linked ?: null))
                : null,
            'is_read' => (bool) $this->is_read,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
