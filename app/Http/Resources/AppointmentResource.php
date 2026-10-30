<?php

namespace App\Http\Resources;

use App\Service\Business\AppointmentAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'appointment_number' => $this->appointment_number,
            'appointment_date' => optional($this->appointment_date)->format('Y-m-d'),
            'appointment_time' => $this->appointment_time,
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
            // Starting a home visit by scanning (App\Support\HomeVisitStart):
            // the code the staff can type into their scanner, and when a
            // scan started the visit. Then the staff gender the booking
            // asked for (App\Support\StaffGender). Null until they exist.
            'home_start_code' => \App\Models\Appointment::hasHomeStart() && $this->isHomeService() ? $this->home_start_code : null,
            'home_scanned_at' => \App\Models\Appointment::hasHomeStart() ? optional($this->home_scanned_at)->toIso8601String() : null,
            'preferred_staff_gender' => \App\Models\Appointment::hasHomeStart() ? $this->preferred_staff_gender : null,
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
            // Reuses AppointmentAvailabilityService's own overlap-checking
            // math (sum of non-cancelled services' variant durations) so
            // this figure never drifts from what booking conflicts are
            // actually checked against.
            'total_duration_minutes' => $this->when(
                $this->relationLoaded('services'),
                fn () => app(AppointmentAvailabilityService::class)->estimatedDurationMinutes($this->resource)
            ),
            // The branch's "service time per booking" limit in minutes
            // (0 = none). The front desk is warned, not stopped, when
            // total_duration_minutes goes past it.
            'service_time_limit_minutes' => $this->whenLoaded(
                'branch',
                fn () => $this->branch ? (int) \App\Service\Client\BookingPolicy::for($this->branch)['max_service_minutes_per_booking'] : 0
            ),
            // For a home visit: the least service time the branch asks for
            // (0 = none, and always 0 at the spa). The front desk is warned,
            // not stopped, when total_duration_minutes is under it.
            'home_service_min_minutes' => $this->whenLoaded(
                'branch',
                fn () => $this->branch && $this->isHomeService() ? (int) \App\Service\Client\BookingPolicy::for($this->branch)['home_service_min_minutes'] : 0
            ),
            // Narrower sibling of total_duration_minutes — only services
            // still Pending/In Progress, for "how much is actually left"
            // displays (see AppointmentAvailabilityService::remainingDurationMinutes).
            'remaining_duration_minutes' => $this->when(
                $this->relationLoaded('services'),
                fn () => app(AppointmentAvailabilityService::class)->remainingDurationMinutes($this->resource)
            ),

            // On leave, absent, late or on a day off: the assigned staff the desk should look at
            // (AssignmentWarningService; read from a list's preload when there is one).
            'assignment_warnings' => \App\Service\Business\AssignmentWarningService::instance()->for($this->resource),

            // The therapist the booking asked for by name, if it named one
            // (see Appointment::requestedStaff). Not who is assigned: that is
            // on each service.
            'requested_therapist' => $this->requested_staff_id && $this->requestedStaff ? [
                'uuid' => $this->requestedStaff->uuid,
                'name' => trim($this->requestedStaff->first_name . ' ' . $this->requestedStaff->last_name),
            ] : null,

            // Booking rules someone went ahead despite: [{rule, message, reason, by, at}].
            'policy_overrides' => array_values((array) ($this->policy_overrides ?? [])),

            'client' => new ClientResource($this->whenLoaded('client')),
            'branch_uuid' => $this->whenLoaded('branch', fn () => $this->branch?->uuid),
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->branch_name),

            // An add-on's main service is another line of this same visit:
            // each is handed its parent from the lines already loaded.
            'services' => AppointmentServiceResource::collection($this->whenLoaded('services', function () {
                $lines = $this->services->keyBy('id');
                $this->services->each(fn ($line) => $line->setRelation(
                    'parent',
                    $line->parent_appointment_service_id ? $lines->get($line->parent_appointment_service_id) : null,
                ));

                return $this->services;
            })),
            'packages' => AppointmentPackageResource::collection($this->whenLoaded('packages')),
            'queue' => new QueueResource($this->whenLoaded('queue')),
            'billing' => (new BillingResource($this->whenLoaded('billing')))->forAppointment($this->resource),

            'created_at' => $this->created_at,
        ];
    }
}
