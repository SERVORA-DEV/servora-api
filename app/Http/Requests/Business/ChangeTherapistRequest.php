<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/appointment-service/{uuid}/change-therapist (AppointmentServiceController::changeTherapist).
// Only while the service is paused (VisitAssignmentService::changeTherapist).
class ChangeTherapistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Who takes over.
            'staff_uuid' => 'required|uuid',
            // Why (the client's preference, the therapist is unwell…): kept on the handed-over row.
            'reason' => 'required|string|max:200',
            // Another room for it, if it moves too.
            'facility_uuid' => 'nullable|uuid',
            // Who is replaced, when several staff do the service at once.
            'assignment_uuid' => 'nullable|uuid',
        ];
    }

    public function messages(): array
    {
        return [
            'staff_uuid.required' => 'Choose who takes over.',
            'reason.required' => 'Say why the therapist is changed.',
        ];
    }
}
