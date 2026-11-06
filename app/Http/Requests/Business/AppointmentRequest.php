<?php

namespace App\Http\Requests\Business;

use App\Rules\ValidEmail;
use App\Rules\PersonName;
use App\Models\Client;
use App\Rules\PhMobile;
use App\Support\ClientProfile;
use App\Support\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validates POST /business/appointment. Minimum required data is
// spa_branch_uuid + appointment_date + appointment_time + a resolvable
// client (rule 1) — services and requested_therapist_uuid are optional.
// client_uuid xor a nested client{} payload: exactly one must be present,
// enforced via required_without_all/prohibits below rather than a custom
// rule, matching this codebase's preference for declarative rules.
class AppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Optional — a front_officer/manager has exactly one accessible
            // branch, so AppointmentService::createAppointment auto-resolves
            // it when omitted (their UI has no branch picker at all). Only a
            // business_owner with more than one branch must specify this.
            'spa_branch_uuid' => 'sometimes|uuid|exists:spa_branches,uuid',
            'appointment_date' => 'required|date',
            'appointment_time' => 'required|date_format:H:i',
            'appointment_type' => ['sometimes', Rule::in(['Reservation', 'Walk-in'])],
            'source' => ['sometimes', Rule::in(['Mobile', 'Front Desk'])],
            'remarks' => 'nullable|string',

            // Home service (HomeServiceRules checks the place and the area).
            'service_location' => ['sometimes', Rule::in(['In Spa', 'Home Service'])],
            'home_address' => 'nullable|required_if:service_location,Home Service|string|max:500',
            'home_address_notes' => 'nullable|string|max:500',
            'home_latitude' => 'nullable|required_if:service_location,Home Service|numeric|between:-90,90',
            'home_longitude' => 'nullable|required_if:service_location,Home Service|numeric|between:-180,180',

            // Minimal — see ClientRequest's docblock; clients only stores
            // simple operational attributes.
            'client_uuid' => ['required_without:client', 'prohibits:client', 'uuid', 'exists:clients,uuid'],
            'client' => 'required_without:client_uuid|array',
            'client.first_name' => ['required_with:client', 'string', 'max:100', new PersonName()],
            'client.last_name' => ['required_with:client', 'string', 'max:100', new PersonName()],
            // A reservation needs a way to reach them; a walk-in doesn't.
            'client.phone_number' => [Rule::requiredIf(fn () => $this->has('client') && $this->input('appointment_type', 'Reservation') !== 'Walk-in'), 'nullable', 'string', 'max:20', new PhMobile()],
            'client.email' => ['nullable', 'email', 'max:255', new ValidEmail()],
            // The spa's own record of them (App\Support\ClientProfile):
            // gender is asked of every new client, age is optional.
            'client.gender' => [Client::hasProfile() ? 'required_with:client' : 'nullable', Rule::in([...ClientProfile::GENDERS, Gender::SHORT_FOR_UNSAID])],
            'client.age' => 'nullable|integer|min:0|max:' . ClientProfile::OLDEST,
            // For a client already on file: what their record is missing,
            // filled in with the booking. Never replaces what is there.
            'client_update' => 'sometimes|array',
            'client_update.gender' => ['nullable', Rule::in([...ClientProfile::GENDERS, Gender::SHORT_FOR_UNSAID])],
            'client_update.age' => 'nullable|integer|min:0|max:' . ClientProfile::OLDEST,
            'client_update.phone_number' => ['nullable', 'string', 'max:20', new PhMobile()],

            'requested_therapist_uuid' => 'nullable|uuid|exists:staff,uuid',

            'services' => 'sometimes|array',
            'services.*.service_variant_uuid' => 'required_with:services|uuid|exists:service_variants,uuid',
            'services.*.quantity' => 'nullable|integer|min:1|max:1000',
            'services.*.discount_amount' => 'nullable|numeric|min:0|max:1000000|decimal:0,2',
            'services.*.notes' => 'nullable|string',

            // A package booked with the appointment (the New Appointment
            // page's menu lists them beside the services).
            'package_uuid' => 'nullable|uuid|exists:packages,uuid',
        ];
    }

    // "Prefer not to say" is kept as 'Other' (App\Support\Gender: clients.gender is too short for it).
    protected function prepareForValidation(): void
    {
        foreach (['client', 'client_update'] as $key) {
            $block = $this->input($key);
            if (is_array($block) && filled($block['gender'] ?? null)) {
                $block['gender'] = Gender::forShortColumn($block['gender']);
                $this->merge([$key => $block]);
            }
        }
    }

    public function messages(): array
    {
        return [
            'client.gender.required_with' => 'Choose the client\'s gender.',
            'client.gender.in' => 'Choose Male, Female or Prefer not to say.',
            'client_update.gender.in' => 'Choose Male, Female or Prefer not to say.',
            'client.phone_number.required' => 'Add the client\'s phone number to book a reservation.',
        ];
    }
}
