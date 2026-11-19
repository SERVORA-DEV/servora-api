<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/frontoffice/attendance/leave (FrontOfficeAttendanceController::leave).
class StaffLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'staff_uuid' => ['required', 'uuid', 'exists:staff,uuid'],
            'type' => ['required', 'in:early,day'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
