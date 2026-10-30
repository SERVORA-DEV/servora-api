<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// Several of one booking's services given staff and/or a facility at once (AppointmentService::assignBatch).
class BatchAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignments' => 'required|array|min:1|max:50',
            'assignments.*.service_uuid' => 'required|uuid',
            // Each line needs a staff member, a facility or both.
            'assignments.*.staff_uuid' => 'nullable|required_without:assignments.*.facility_uuid|uuid|exists:staff,uuid',
            'assignments.*.facility_uuid' => 'nullable|required_without:assignments.*.staff_uuid|uuid|exists:facilities,uuid',
        ];
    }
}
