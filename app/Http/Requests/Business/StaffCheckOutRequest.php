<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/frontoffice/attendance/check-out (FrontOfficeAttendanceController::checkOut).
class StaffCheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['staff_uuid' => ['required', 'uuid', 'exists:staff,uuid']];
    }
}
