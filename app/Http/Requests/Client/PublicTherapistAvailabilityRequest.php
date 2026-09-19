<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

// Validates the query string of the public
// GET /spas/{uuid}/therapists availability lookup, which the client mobile
// booking flow calls once it knows the date, time and total service
// duration. Unauthenticated like the rest of the /spas/* lookups, so it
// authorizes freely and leans on the branch lookup (publicFindByUuid) to
// 404 anything not Verified+Active.
//
// Date/time formats mirror ClientAppointmentRequest so the same values the
// app will later POST are the ones it checked availability with.
class PublicTherapistAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            // Optional: the flow can ask before services are picked. 60
            // minutes is the same "assume a normal service" fallback
            // AppointmentAvailabilityService uses elsewhere for an
            // appointment with nothing on it yet.
            'duration_minutes' => 'nullable|integer|min:1|max:1440',
        ];
    }

    public function attributes(): array
    {
        return [
            'date' => 'date',
            'time' => 'time',
            'duration_minutes' => 'duration',
        ];
    }
}
