<?php

namespace App\Http\Requests\Business;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BranchScheduleUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership of the schedule row (via its branch) is enforced in
     * BranchScheduleService::resolveOwnedSchedule, not here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * No rule for day_of_week or spa_branch_uuid on purpose — a row's day
     * and branch are fixed at creation; ->validated() only returns keys with
     * rules, so either would silently be dropped from the payload even if
     * sent.
     *
     * No after:opening_time / after:break_start on the closing/end times —
     * see BranchScheduleRequest for why (overnight hours support).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'opening_time' => 'nullable|date_format:H:i',
            'closing_time' => 'nullable|date_format:H:i',

            'break_start' => 'nullable|date_format:H:i',
            'break_end' => 'nullable|date_format:H:i',

            'is_closed' => 'sometimes|boolean',
        ];
    }
}
