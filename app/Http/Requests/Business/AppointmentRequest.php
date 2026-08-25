<?php

namespace App\Http\Requests\Business;

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

            // Minimal — see ClientRequest's docblock; clients only stores
            // simple operational attributes.
            'client_uuid' => ['required_without:client', 'prohibits:client', 'uuid', 'exists:clients,uuid'],
            'client' => 'required_without:client_uuid|array',
            'client.first_name' => 'required_with:client|string|max:100',
            'client.last_name' => 'required_with:client|string|max:100',
            'client.phone_number' => 'nullable|string|max:20',
            'client.email' => 'nullable|email|max:255',

            'requested_therapist_uuid' => 'nullable|uuid|exists:staff,uuid',

            'services' => 'sometimes|array',
            'services.*.service_variant_uuid' => 'required_with:services|uuid|exists:service_variants,uuid',
            'services.*.quantity' => 'nullable|integer|min:1',
            'services.*.discount_amount' => 'nullable|numeric|min:0',
            'services.*.notes' => 'nullable|string',
        ];
    }
}
