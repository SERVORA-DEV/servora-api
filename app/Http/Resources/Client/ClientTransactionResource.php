<?php

namespace App\Http\Resources\Client;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// A receipt for one visit: the billing the front desk raised on the
// client's appointment, and the payments recorded against it.
class ClientTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $appointment = $this->appointment;
        $payments = $this->payments->where('payment_status', 'Paid');
        $paid = (float) $payments->sum('amount');

        return [
            'uuid' => $this->uuid,
            'billing_number' => $this->billing_number,
            'amount' => (float) $this->amount,
            'paid' => $paid,
            'balance' => max((float) $this->amount - $paid, 0),
            'status' => $this->status,
            'issued_at' => optional($this->issued_at)->toIso8601String(),
            'paid_at' => optional($this->paid_at)->toIso8601String(),
            'appointment_uuid' => $appointment?->uuid,
            'appointment_number' => $appointment?->appointment_number,
            'appointment_date' => $appointment ? optional($appointment->appointment_date)->format('Y-m-d') : null,
            'branch_name' => $appointment?->branch?->branch_name,
            'business_name' => $appointment?->branch?->business?->business_name,
            'services' => $appointment
                ? $appointment->services->where('status', '!=', 'Cancelled')->map(fn ($s) => [
                    'name' => $s->serviceVariant?->service?->name,
                    'price' => (float) $s->subtotal,
                ])->values()
                : [],
            'payments' => $payments->map(fn ($p) => [
                'method' => $p->payment_method,
                'amount' => (float) $p->amount,
                'reference_number' => $p->reference_number,
                'paid_at' => optional($p->paid_at)->toIso8601String(),
            ])->values(),
        ];
    }
}
