<?php

namespace App\Http\Requests\Business;

use App\Models\StaffTimeOff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// POST staff/{uuid}/time-off — one stretch of days off. "Other" needs a note
// so it's clear later what it was.
class StaffTimeOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $note = $this->input('note');
        $this->merge(['note' => is_string($note) && trim($note) !== '' ? trim($note) : null]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(StaffTimeOff::TYPES)],
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'note' => ['nullable', 'string', 'max:120', Rule::requiredIf(fn () => $this->input('type') === 'other')],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Choose Leave, Sick, Absent or Other.',
            'starts_on.required' => 'Choose the first day.',
            'ends_on.required' => 'Choose the last day.',
            'ends_on.after_or_equal' => 'The last day is before the first.',
            'note.required' => 'Say what it is, so it is clear later.',
        ];
    }
}
