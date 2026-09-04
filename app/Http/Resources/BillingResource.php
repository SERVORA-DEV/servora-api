<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'billing_number' => $this->billing_number,
            'billing_type' => $this->billing_type,
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'issued_at' => optional($this->issued_at)->toIso8601String(),
            'paid_at' => optional($this->paid_at)->toIso8601String(),
            'plan_name' => $this->whenLoaded('subscription', fn () => optional($this->subscription?->plan)->name),
            'appointment_uuid' => $this->whenLoaded('appointment', fn () => $this->appointment?->uuid),
            // Full appointment context (client, branch, itemized services) —
            // populated only when AppointmentService::getBilling's eager
            // loads are present; the appointment-detail page's own nested
            // billing (loaded without these relations) stays lean.
            'appointment' => new AppointmentResource($this->whenLoaded('appointment')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
