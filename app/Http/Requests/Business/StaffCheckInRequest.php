<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/frontoffice/attendance/check-in (FrontOfficeAttendanceController::checkIn).
class StaffCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'staff_uuid' => ['required', 'uuid', 'exists:staff,uuid'],
            'covering_for_staff_uuid' => ['nullable', 'uuid', 'exists:staff,uuid'],
        ];
    }
}
