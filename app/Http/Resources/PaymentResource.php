<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'payment_method' => $this->payment_method,
            'reference_number' => $this->reference_number,
            'amount' => (float) $this->amount,
            'amount_tendered' => $this->amount_tendered !== null ? (float) $this->amount_tendered : null,
            'change_given' => $this->change_given !== null ? (float) $this->change_given : null,
            'payment_status' => $this->payment_status,
            'paid_at' => optional($this->paid_at)->toIso8601String(),
            'refunded_amount' => (float) $this->refunded_amount,
            'refund_reason' => $this->refund_reason,
            'voided_at' => optional($this->voided_at)->toIso8601String(),
            // Who took it, by the name they had then (the payment's own
            // copy); the account's name now only for a payment with none.
            'received_by_name' => $this->resource->received_by_name
                ?? $this->whenLoaded('receiver', fn () => \App\Models\Payment::nameOf($this->receiver)),
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
        ];
    }
}
