<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// Validates PUT /business/branch/{uuid}/schedule — a branch's whole week in
// one request. The per-day rules are BranchScheduleRequest's: plain times of
// day, and no "closing after opening" check, because a closing time at or
// before the opening time means 24 hours or closing the next day.
//
// Branch ownership is checked in BranchScheduleService::saveWeek, not here.
class BranchWeekScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'days' => 'required|array|min:1|max:7',
            'days.*.day_of_week' => 'required|distinct|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'days.*.opening_time' => 'nullable|date_format:H:i',
            'days.*.closing_time' => 'nullable|date_format:H:i',
            'days.*.is_closed' => 'sometimes|boolean',
        ];
    }
}
