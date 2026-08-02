<?php

namespace App\Http\Requests\Business;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BranchScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership of spa_branch_uuid is enforced in
     * BranchScheduleService::resolveOwnedBranch, not here — this only
     * checks the uuid resolves to *some* branch, not that it's this
     * owner's.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * No after:opening_time / after:break_start on the closing/end times —
     * these columns are plain times-of-day with no "next day" flag, and a
     * same-day comparison would reject perfectly valid overnight hours
     * (e.g. open 22:00, close 02:00 for a late-night branch — see the
     * "Open Late" highlight option elsewhere in the app). closing_time <
     * opening_time is treated as closing the following day, not an error.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'spa_branch_uuid' => 'required|string|exists:spa_branches,uuid',

            'day_of_week' => 'required|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',

            'opening_time' => 'nullable|date_format:H:i',
            'closing_time' => 'nullable|date_format:H:i',

            'break_start' => 'nullable|date_format:H:i',
            'break_end' => 'nullable|date_format:H:i',

            'is_closed' => 'sometimes|boolean',
        ];
    }
}
