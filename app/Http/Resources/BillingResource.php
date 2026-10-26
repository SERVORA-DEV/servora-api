<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Models\AppointmentServiceItem;
use App\Service\Business\BillingAdjustmentService;
use App\Service\Business\BillingService;
use App\Service\Client\BookingPolicy;
use App\Support\FinishedVisit;
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

    /** @return list<array{service_uuid: string, charge: float, paid: float, refunded: float, refundable: float}> */
    private function serviceRefunds(): array
    {
        $plan = app(BillingService::class)->serviceRefunds($this->resource);

        return $this->appointment->services
            ->filter(fn ($s) => isset($plan[$s->id]))
            ->map(fn ($s) => ['service_uuid' => $s->uuid] + $plan[$s->id])
            ->values()
            ->all();
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
            // Refunded before payment, on top of the discount (BillAdjustment "credit").
            'credit_amount' => (float) ($this->credit_amount ?? 0),
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
            // The visit is finished and paid: nothing on this bill can be
            // voided or refunded any more (FinishedVisit).
            'locked' => FinishedVisit::isLocked(($this->relationLoaded('appointment') ? $this->appointment : $this->appointmentContext)?->status),
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
            // What of the money belongs to each service, and what can still be
            // handed back for it (App\Support\ServiceRefunds) — for the
            // checkout, which has the visit's services loaded.
            'service_refunds' => $this->when(
                $this->relationLoaded('appointment') && $this->appointment?->relationLoaded('services') && $this->relationLoaded('payments'),
                fn () => $this->serviceRefunds(),
            ),
            // Whether a refund can be for a single service on this server yet.
            'service_refunds_enabled' => AppointmentServiceItem::tracksRefunds(),
            // Changes after the bill was paid (a voucher afterwards, a refund),
            // applied or waiting for approval, and whether this person can
            // make one and what happens when they do (BillingAdjustmentService).
            // Only on the bill page, like service_refunds.
            'adjustments' => $this->when(
                $this->relationLoaded('appointment') && $this->appointment?->relationLoaded('services') && $this->relationLoaded('payments'),
                fn () => app(BillingAdjustmentService::class)->forBill($this->resource, $request->user()),
            ),
            'adjust' => $this->when(
                $this->relationLoaded('appointment') && $this->appointment?->relationLoaded('services') && $this->relationLoaded('payments'),
                fn () => app(BillingAdjustmentService::class)->optionsFor($this->resource, $request->user()),
            ),
        ];
    }
}
