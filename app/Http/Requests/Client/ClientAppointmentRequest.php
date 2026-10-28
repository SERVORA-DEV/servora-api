<?php

namespace App\Http\Requests\Client;

use App\Models\Client;
use App\Support\ClientProfile;
use App\Support\StaffGender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\NoEmoji;

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
            // The staff gender they would rather have (App\Support\StaffGender).
            'preferred_staff_gender' => ['nullable', Rule::in(StaffGender::PREFERENCES)],
            'remarks' => 'nullable|string|max:500',
            // The client saw they already have a booking at this time and books anyway.
            'acknowledge_overlap' => 'sometimes|boolean',
            // One voucher from their wallet for this booking (held until it is billed).
            'voucher_uuid' => 'nullable|uuid',
            // A first-visit voucher not claimed yet (the program's uuid): it is
            // given to the client with this booking and used on it.
            'first_visit_program_uuid' => 'nullable|uuid',

            // Home service (HomeServiceRules checks the place and the area).
            'service_location' => 'sometimes|in:In Spa,Home Service',
            'home_address' => ['nullable', 'required_if:service_location,Home Service', 'string', 'max:500', new NoEmoji()],
            'home_address_notes' => 'nullable|string|max:500',
            'home_latitude' => 'nullable|required_if:service_location,Home Service|numeric|between:-90,90',
            'home_longitude' => 'nullable|required_if:service_location,Home Service|numeric|between:-180,180',
            'client' => 'required|array',
            'client.first_name' => ['required', 'string', 'max:100', new NoEmoji()],
            'client.last_name' => ['required', 'string', 'max:100', new NoEmoji()],
            'client.phone_number' => ['required', 'string', 'max:20', new NoEmoji()],
            // Asked once: an account that already said has nothing to add.
            // Age is optional (App\Support\ClientProfile).
            'client.gender' => [
                Client::hasProfile() && ClientProfile::gender($this->user()?->gender) === null ? 'required' : 'nullable',
                Rule::in(ClientProfile::GENDERS),
            ],
            'client.age' => 'nullable|integer|min:0|max:' . ClientProfile::OLDEST,
        ];
    }

    // Shown to the mobile user as-is, so avoid dotted field paths like
    // "client.phone number" in validation messages.
    public function attributes(): array
    {
        return [
            'client.first_name' => 'first name',
            'client.last_name' => 'last name',
            'client.phone_number' => 'mobile number',
            'client.gender' => 'gender',
            'client.age' => 'age',
            'appointment_date' => 'date',
            'appointment_time' => 'time',
            'home_address' => 'address',
            'home_latitude' => 'map pin',
            'home_longitude' => 'map pin',
        ];
    }

    public function messages(): array
    {
        return [
            'services.required_without' => 'Please choose at least one service or a package.',
            'package_uuid.required_without' => 'Please choose at least one service or a package.',
            'appointment_date.after_or_equal' => 'Please choose a future date.',
            'client.gender.required' => 'Please choose your gender.',
            'home_address.required_if' => 'Please enter the address for the home service.',
            'home_latitude.required_if' => 'Please pin the address on the map.',
            'home_longitude.required_if' => 'Please pin the address on the map.',
        ];
    }
}
