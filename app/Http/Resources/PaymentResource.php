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
            'payment_status' => $this->payment_status,
            'paid_at' => optional($this->paid_at)->toIso8601String(),
            'refunded_amount' => (float) $this->refunded_amount,
            'refund_reason' => $this->refund_reason,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
        ];
    }
}
