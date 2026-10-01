<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Service\Client\BookingPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingResource extends JsonResource
{
    // The appointment this bill is nested under (AppointmentResource), for
    // the cancellation-policy refund — without loading billing->appointment,
    // which would nest the appointment inside itself.
    private ?Appointment $appointmentContext = null;

    public function forAppointment(?Appointment $appointment): static
    {
        $this->appointmentContext = $appointment;

        return $this;
    }

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
            // Where the discount came from: a customer program the front desk
            // picked, one of the client's vouchers, or typed in (null).
            'discount_source' => $this->client_voucher_id ? 'voucher' : ($this->discount_program_id ? 'program' : null),
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
            // What this charge was for, as written when it was paid (e.g.
            // "Upgrade: Basic → Pro") — plan_name above is the subscription's
            // plan now, which an upgrade changes.
            'description' => in_array($this->billing_type, ['Subscription', 'Membership'], true) ? $this->remarks : null,
            'appointment_uuid' => $this->whenLoaded('appointment', fn () => $this->appointment?->uuid),
            // Full appointment context (client, branch, itemized services) —
            // populated only when AppointmentService::getBilling's eager
            // loads are present; the appointment-detail page's own nested
            // billing (loaded without these relations) stays lean.
            'appointment' => new AppointmentResource($this->whenLoaded('appointment')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'policy_refund' => $this->policyRefund(),
        ];
    }

    // What the branch's cancellation policy says to give back on a cancelled
    // booking (Business Defaults → Refund by cancellation policy, or the
    // branch's own tier). A suggestion the refund form starts from — staff
    // can still refund more or less, up to what was paid.
    private function policyRefund(): ?array
    {
        $appointment = $this->relationLoaded('appointment') ? $this->appointment : $this->appointmentContext;
        if (! $this->relationLoaded('payments') || ! $appointment) {
            return null;
        }

        if ($appointment?->status !== Appointment::STATUS_CANCELLED || ! $appointment->branch) {
            return null;
        }

        $taken = $this->payments->whereIn('payment_status', ['Paid', 'Refunded']);
        $paid = round((float) $taken->sum(fn ($p) => (float) $p->amount), 2);
        $refunded = round((float) $taken->sum(fn ($p) => (float) $p->refunded_amount), 2);
        if ($paid <= 0) {
            return null;
        }

        $policy = BookingPolicy::for($appointment->branch);
        $percent = $policy['refund_percent'];

        return [
            'tier' => $policy['cancellation_policy_tier'],
            'percent' => $percent,
            'paid' => $paid,
            // Less anything already handed back, never below zero.
            'amount' => max(0, min(round($paid - $refunded, 2), round($paid * $percent / 100 - $refunded, 2))),
        ];
    }
}
