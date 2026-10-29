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

        return BookingPolicy::changeProblem(
            BookingPolicy::for($branch), $startsAt, $action,
            Appointment::countsReschedules() ? (int) $appointment->client_reschedule_count : 0,
        );
    }

    private function bookedVoucher(): ?array
    {
        if (! Appointment::holdsVouchers() || ! $this->client_voucher_id) {
            return null;
        }
        $voucher = $this->voucher;
        $program = $voucher?->dealProgram();

        return $program ? [
            'uuid' => $voucher->uuid,
            'name' => $program->name,
            'deal' => app(\App\Service\Business\CustomerProgramService::class)->dealText($program),
        ] : null;
    }

    /** @return array{reschedules_used: ?int, reschedules_left: ?int} */
    private function rescheduleAllowance(): array
    {
        if (! Appointment::countsReschedules() || ! $this->branch) {
            return ['reschedules_used' => null, 'reschedules_left' => null];
        }
        $used = (int) $this->client_reschedule_count;
        $limit = (int) (BookingPolicy::for($this->branch)['max_reschedules_per_booking'] ?? 0);

        return ['reschedules_used' => $used, 'reschedules_left' => max(0, $limit - $used)];
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

            // Home service (HomeServiceRules): 'In Spa' or 'Home Service',
            // and for a home visit the client's pinned address and the fee
            // that is part of total_amount.
            'service_location' => $this->service_location ?? 'In Spa',
            'is_home_service' => $this->isHomeService(),
            'home_address' => $this->home_address,
            'home_address_notes' => $this->home_address_notes,
            'home_latitude' => $this->home_latitude,
            'home_longitude' => $this->home_longitude,
            'home_distance_km' => $this->home_distance_km,
            'home_service_fee' => (float) $this->home_service_fee,
            // What the client shows their staff to start a home visit
            // (App\Support\HomeVisitStart): the code, and the text its QR is
            // drawn from. Null for a visit to the spa.
            'home_start_code' => Appointment::hasHomeStart() && $this->isHomeService() ? $this->home_start_code : null,
            'home_start_qr' => Appointment::hasHomeStart() && $this->isHomeService() && $this->home_start_code
                ? \App\Support\HomeVisitStart::qrText($this->home_start_code)
                : null,
            'preferred_staff_gender' => Appointment::hasHomeStart() ? $this->preferred_staff_gender : null,
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
                'logo_url' => ImageUploadService::logoUrl($business->business_logo),
            ] : null,

            // A cancelled or missed booking still lists what was booked;
            // otherwise services the front desk removed are left out.
            'services' => (in_array($this->status, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true)
                    ? $this->services
                    : $this->services->where('status', '!=', 'Cancelled'))
                ->values()
                ->map(fn ($s) => [
                    'uuid' => $s->uuid,
                    'name' => $s->displayName(),
                    'duration_minutes' => $s->lengthMinutes(),
                    'price' => (float) $s->subtotal,
                    'status' => $s->status,
                    'package_name' => $s->sourceAppointmentPackage?->displayName(),
                ]),
            'packages' => $this->packages->map(fn ($p) => [
                'uuid' => $p->uuid,
                'name' => $p->displayName(),
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
            // A spa is rated once (Review::ratedBranch): `spa_rated` when the
            // client already has, on this visit or another one there.
            'spa_rated' => $spaRated = $this->review !== null || (bool) $this->resource->getAttribute('spa_rated'),
            'can_review' => $this->status === Appointment::STATUS_COMPLETED && ! $spaRated,
            // The limit on moving this booking in the app: how many times it
            // has been, and how many are left (null: no count yet).
            ...$this->rescheduleAllowance(),
            // The voucher booked with (held for this booking, put on the bill
            // when it is made); null for none.
            'voucher' => $this->bookedVoucher(),

            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
