<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// GET api/business/frontoffice/time-off, GET api/business/time-off (StaffTimeOffController::range).
class TimeOffRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            // bail: the four-month check below only sees a real date (an array used to crash it).
            'to' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:from', function ($attribute, $value, $fail) {
                if (! is_string($this->input('from'))) {
                    return; // `from` has its own error
                }
                if (strtotime((string) $value) - strtotime((string) $this->input('from')) > 140 * 86400) {
                    $fail('Choose up to four months at a time.');
                }
            }],
        ];
    }
}
