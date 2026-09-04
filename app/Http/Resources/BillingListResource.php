<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Flattened row shape for the owner/manager "Billing & Payments" list page —
// distinct from BillingResource (used for the single-billing detail
// response) because the list view needs client/appointment/service fields
// pulled up to the top level rather than nested, to keep the frontend table
// mapping a flat pass-through instead of reaching through several levels of
// optional relations per row.
class BillingListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $appointment = $this->appointment;
        $client = $appointment?->client;

        return [
            'uuid' => $this->uuid,
            'billing_number' => $this->billing_number,
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'issued_at' => optional($this->issued_at)->toIso8601String(),
            'paid_at' => optional($this->paid_at)->toIso8601String(),

            'branch_uuid' => $this->branch?->uuid,
            'branch_name' => $this->branch?->branch_name,

            'appointment_uuid' => $appointment?->uuid,
            'appointment_number' => $appointment?->appointment_number,
            'appointment_date' => optional($appointment?->appointment_date)->format('Y-m-d'),
            'appointment_time' => $appointment?->appointment_time,

            'client_name' => $client ? trim("{$client->first_name} {$client->last_name}") : null,
            'client_phone' => $client?->phone_number,

            'services' => $appointment
                ? $appointment->services->map(fn ($service) => $service->serviceVariant?->service?->name)->filter()->values()
                : [],

            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
