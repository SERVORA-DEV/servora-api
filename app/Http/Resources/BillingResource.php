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
            'subtotal' => (float) ($this->subtotal ?? $this->amount),
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value !== null ? (float) $this->discount_value : null,
            'discount_amount' => (float) $this->discount_amount,
            'discount_reason' => $this->discount_reason,
            // Still owed: amount minus what's been paid (voided payments
            // excluded). Nothing is owed on a Paid, Refunded or Cancelled bill —
            // a refund hands money back, it doesn't reopen the balance.
            'balance' => $this->whenLoaded('payments', fn () => in_array($this->status, ['Paid', 'Refunded', 'Cancelled'], true)
                ? 0.0
                : max(0, round((float) $this->amount - $this->payments
                    ->where('payment_status', 'Paid')
                    ->sum(fn ($p) => (float) $p->amount - (float) $p->refunded_amount), 2))),
            'refunded_total' => $this->whenLoaded('payments', fn () => round((float) $this->payments->sum(fn ($p) => (float) $p->refunded_amount), 2)),
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
