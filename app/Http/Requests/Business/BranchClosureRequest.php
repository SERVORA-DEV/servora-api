<?php

namespace App\Http\Requests\Business;

use App\Support\BranchClosures;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validates a temporary closure of a branch — creating one, editing one, and
// the "which bookings would this land on?" preview (which only needs the
// window, so the reason is optional there).
//
// Times are the branch's own wall-clock time. With all_day, only the dates
// of starts_at / ends_at are used: the first and the last closed day.
//
// Whether the branch is the caller's, and whether the window is allowed
// (not in the past, not too long, not over another closure), is checked in
// BranchClosureService.
class BranchClosureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $preview = $this->isMethod('get');

        return [
            'starts_at' => 'required|date_format:Y-m-d H:i',
            'ends_at' => 'required|date_format:Y-m-d H:i',
            'all_day' => 'sometimes|boolean',
            'reason' => [$preview ? 'sometimes' : 'required', Rule::in(BranchClosures::REASONS)],
            'note' => 'nullable|string|max:200',
        ];
    }
}
