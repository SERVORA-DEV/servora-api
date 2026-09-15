<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

// Validates POST /client/appointments (mobile self-booking). Deliberately
// narrower than the front-desk AppointmentRequest: no discount, quantity,
// type, source or status fields — those are fixed server-side in
// AppointmentService::createClientAppointment. At least one service or a
// package is required so a client can't create an empty booking.
class ClientAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'spa_branch_uuid' => 'required|uuid',
            'appointment_date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'appointment_time' => 'required|date_format:H:i',
            'services' => 'nullable|array|required_without:package_uuid',
            'services.*.service_variant_uuid' => 'required|uuid',
            'package_uuid' => 'nullable|uuid|required_without:services',
            'requested_therapist_uuid' => 'nullable|uuid',
            'remarks' => 'nullable|string|max:500',
            'client' => 'required|array',
            'client.first_name' => 'required|string|max:100',
            'client.last_name' => 'required|string|max:100',
            'client.phone_number' => 'required|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'services.required_without' => 'Please choose at least one service or a package.',
            'package_uuid.required_without' => 'Please choose at least one service or a package.',
            'appointment_date.after_or_equal' => 'Please choose a future date.',
        ];
    }
}
