<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of an appointment list (GET /business/appointment?view=list): only what a table
 * shows. Opening the visit reads the whole of it (AppointmentResource).
 */
class AppointmentListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $client = $this->client;

        return [
            'uuid' => $this->uuid,
            'appointment_number' => $this->appointment_number,
            'appointment_type' => $this->appointment_type,
            'is_home_service' => $this->isHomeService(),
            'home_address' => $this->home_address,
            'appointment_date' => optional($this->appointment_date)->format('Y-m-d'),
            'appointment_time' => $this->appointment_time,
            'status' => $this->status,
            'total_amount' => (float) $this->total_amount,
            // Written as ClientResource writes it.
            'client' => ['full_name' => $client ? trim("{$client->first_name} {$client->last_name}") : null],
            'branch_uuid' => $this->branch?->uuid,
            'branch_name' => $this->branch?->branch_name,
            // What the visit is: not a service taken off it (removed, or voided) — unless the whole
            // booking was called off, which still shows what was booked. Its staff: not one taken off,
            // nor one who handed it over while it was paused.
            'services' => $this->services
                ->when(! in_array($this->status, [\App\Enums\AppointmentStatus::Cancelled->value, \App\Enums\AppointmentStatus::NoShow->value], true),
                    fn ($list) => $list->where('status', '!=', \App\Enums\AppointmentServiceStatus::Cancelled->value))
                ->map(fn ($s) => [
                    'service_name' => $s->displayName(),
                    'therapist_assignments' => $s->therapistAssignments
                        ->whereNotIn('assignment_status', [\App\Enums\AssignmentStatus::Cancelled->value, \App\Enums\AssignmentStatus::HandedOver->value])
                        ->map(fn ($a) => ['staff_name' => $a->staffName()])->values(),
                ])->values(),
            'queue' => $this->queue ? [
                'queue_number' => $this->queue->queue_number,
                'queue_status' => $this->queue->queue_status,
                'line_at' => $this->queue->lineTime()->copy()->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
            ] : null,
            'created_at' => $this->created_at,
        ];
    }
}
