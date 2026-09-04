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
            // A therapist may be assigned before a room is picked, or a room
            // reserved before a therapist is picked (rule 7: room and staff
            // assignment are independent) — but at least one of the two must
            // be present.
            'staff_uuid' => 'nullable|required_without:facility_uuid|uuid|exists:staff,uuid',
            'facility_uuid' => 'nullable|required_without:staff_uuid|uuid|exists:facilities,uuid',
        ];
    }
}
