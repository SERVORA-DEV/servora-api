<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

// Validates PATCH /business/attendance/{uuid} — a targeted correction on an
// already-existing row (date is fixed, resolved from the uuid), unlike
// AttendanceRequest's full upsert-by-(staff,date). Every field is optional
// since a correction might only touch one thing (e.g. just the checkout
// time), but at least one must be present or there's nothing to correct.
class AttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['Present', 'Late', 'Absent', 'Half Day', 'On Leave', 'Holiday'])],
            'check_in_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'check_out_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'remarks' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasAny(['status', 'check_in_at', 'check_out_at', 'remarks'])) {
                $validator->errors()->add('status', 'Provide at least one field to correct.');
            }
        });
    }
}
