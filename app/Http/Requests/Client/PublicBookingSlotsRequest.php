<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

// Query string of the public GET /spas/{uuid}/slots lookup — the start
// times a client can book on one day for a given total service duration,
// optionally with one therapist (see BookingSlotService). Unauthenticated
// like the other /spas/* lookups; exclude_appointment_uuid is only honoured
// for the signed-in client's own booking (rescheduling around itself).
class PublicBookingSlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date_format:Y-m-d',
            'duration_minutes' => 'required|integer|min:1|max:600',
            'therapist_uuid' => 'nullable|uuid',
            'exclude_appointment_uuid' => 'nullable|uuid',
        ];
    }

    public function attributes(): array
    {
        return [
            'date' => 'date',
            'duration_minutes' => 'duration',
            'therapist_uuid' => 'therapist',
        ];
    }
}
