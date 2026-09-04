<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// Validates PATCH staff/{uuid}/schedule — replaces a staff member's current
// weekly schedule wholesale (same "edited as a full set" idiom as
// StaffServiceRequest's service_uuids array). Days omitted from the array are
// treated as "not set" (no constraint) by StaffScheduleService, distinct from
// an explicit is_day_off row.
class StaffScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'days' => 'sometimes|array',
            'days.*.day_of_week' => 'required_with:days|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday|distinct',
            'days.*.is_day_off' => 'sometimes|boolean',
            'days.*.start_time' => 'nullable|date_format:H:i',
            'days.*.end_time' => 'nullable|date_format:H:i',
            'days.*.break_start' => 'nullable|date_format:H:i',
            'days.*.break_end' => 'nullable|date_format:H:i',
        ];
    }
}
