<?php

namespace App\Http\Resources\Client;

use App\Models\Appointment;
use App\Service\Business\AppointmentAvailabilityService;
use App\Service\Client\BookingPolicy;
use App\Services\ImageUploadService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// One of the signed-in client's own bookings, shaped for the mobile app:
// the spa it's at (name, address, pin, cover, logo), what was booked, who's
// doing it, where it stands in the queue, what was paid, and which actions
// the app may offer. Expects ClientBookingService::RELATIONS loaded.
class ClientAppointmentResource extends JsonResource
{
    // A client may cancel or move a booking themselves only while it's
    // still just a reservation — not once they've checked in (the front
    // desk owns it from there) and not after its start time has passed.
    public static function clientCanChange(Appointment $appointment): bool
    {
        if ($appointment->status !== Appointment::STATUS_SCHEDULED) {
            return false;
        }

        $startsAt = Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->appointment_time);

        return $startsAt->gt(now());
    }

    // Why the client can't cancel/reschedule this booking from the app right
    // now, or null: still just a reservation (above), then the spa's own
    // rules — allow cancel/reschedule and the cancellation window.
    public static function changeProblem(Appointment $appointment, string $action): ?string
    {
        if (! self::clientCanChange($appointment)) {
            return $action === 'cancel'
                ? 'This booking can no longer be cancelled from the app. Please contact the spa.'
                : 'This booking can no longer be rescheduled from the app. Please contact the spa.';
        }

        $branch = $appointment->branch;
        if (! $branch) {
            return null;
        }

        $startsAt = Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->appointment_time);

        return BookingPolicy::changeProblem(BookingPolicy::for($branch), $startsAt, $action);
    }

    public function toArray(Request $request): array
    {
        $branch = $this->branch;
        $business = $branch?->business;
        $billing = $this->billing;
        // Net of refunds, the same as the spa's own billing screens.
        $paid = $billing
            ? (float) $billing->payments->where('payment_status', 'Paid')->sum(fn ($p) => (float) $p->amount - (float) ($p->refunded_amount ?? 0))
            : 0.0;

        $therapists = $this->services
            ->flatMap(fn ($s) => $s->therapistAssignments->whereNotIn('assignment_status', ['Cancelled'])->map(fn ($a) => $a->staff))
            ->filter()
            ->unique('id')
            ->map(fn ($staff) => ['uuid' => $staff->uuid, 'name' => trim("{$staff->first_name} {$staff->last_name}")])
            ->values();

        return [
            'uuid' => $this->uuid,
            'appointment_number' => $this->appointment_number,
            'appointment_date' => optional($this->appointment_date)->format('Y-m-d'),
            'appointment_time' => substr((string) $this->appointment_time, 0, 5),
            'appointment_type' => $this->appointment_type,
            'source' => $this->source,
            'status' => $this->status,
            'check_in_at' => optional($this->check_in_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'remarks' => $this->remarks,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'total_amount' => (float) $this->total_amount,
            'total_duration_minutes' => app(AppointmentAvailabilityService::class)->estimatedDurationMinutes($this->resource),

            'branch' => $branch ? [
                'uuid' => $branch->uuid,
                'name' => $branch->branch_name,
                'formatted_address' => $branch->formatted_address,
                'latitude' => $branch->latitude !== null ? (float) $branch->latitude : null,
                'longitude' => $branch->longitude !== null ? (float) $branch->longitude : null,
                'phone_number' => $branch->phone_number ?? null,
                'cover_photo_url' => $branch->coverPhotoUrl(),
            ] : null,
            'business' => $business ? [
                'uuid' => $business->uuid,
                'name' => $business->business_name,
                'logo_url' => ImageUploadService::url($business->business_logo),
            ] : null,

            // A cancelled booking still lists what was booked; otherwise
            // services the front desk removed are left out.
            'services' => ($this->status === Appointment::STATUS_CANCELLED
                    ? $this->services
                    : $this->services->where('status', '!=', 'Cancelled'))
                ->values()
                ->map(fn ($s) => [
                    'uuid' => $s->uuid,
                    'name' => $s->serviceVariant?->service?->name,
                    'duration_minutes' => $s->serviceVariant?->duration_minutes,
                    'price' => (float) $s->subtotal,
                    'status' => $s->status,
                    'package_name' => $s->sourceAppointmentPackage?->package?->name,
                ]),
            'packages' => $this->packages->map(fn ($p) => [
                'uuid' => $p->uuid,
                'name' => $p->package?->name,
            ])->values(),
            'therapists' => $therapists,

            'queue' => $this->queue ? [
                'queue_number' => $this->queue->queue_number,
                'queue_status' => $this->queue->queue_status,
                'called_at' => optional($this->queue->called_at)->toIso8601String(),
            ] : null,

            'payment' => $billing ? [
                'billing_number' => $billing->billing_number,
                'amount' => (float) $billing->amount,
                'paid' => $paid,
                'balance' => max((float) $billing->amount - $paid, 0),
                'status' => $billing->status,
                'methods' => $billing->payments->where('payment_status', 'Paid')->pluck('payment_method')->unique()->values(),
                'paid_at' => optional($billing->paid_at)->toIso8601String(),
            ] : null,

            'review' => $this->review ? new ClientReviewResource($this->review) : null,

            'can_cancel' => ($cancelProblem = self::changeProblem($this->resource, 'cancel')) === null,
            'can_reschedule' => ($rescheduleProblem = self::changeProblem($this->resource, 'reschedule')) === null,
            // Why not, for a still-upcoming booking the app shows the buttons
            // for (e.g. inside the spa's cancellation window).
            'cancel_blocked_reason' => $this->status === Appointment::STATUS_SCHEDULED ? $cancelProblem : null,
            'reschedule_blocked_reason' => $this->status === Appointment::STATUS_SCHEDULED ? $rescheduleProblem : null,
            'can_review' => $this->status === Appointment::STATUS_COMPLETED && ! $this->review,

            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
