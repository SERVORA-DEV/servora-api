<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class TherapistAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'staff_uuid' => 'required|uuid|exists:staff,uuid',
            // Optional — a therapist may be assigned before a room is
            // picked (rule 7: room assignment is independent).
            'facility_uuid' => 'nullable|uuid|exists:facilities,uuid',
        ];
    }
}
