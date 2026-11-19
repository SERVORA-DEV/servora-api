<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/owner/registration-change (BusinessRegistrationChangeController::submit).
class RegistrationChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A DTI certificate number is digits only.
            'registration_number' => ['required', 'string', 'max:100', 'regex:/^\d+$/'],
            'registered_business_name' => 'required|string|max:255',
            'registered_owner_name' => 'required|string|max:255',
            'note' => 'nullable|string|max:500',
            'registration_document' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.(int) config('uploads.document_max_size_kb', 8192)],
        ];
    }

    public function messages(): array
    {
        return [
            'registration_number.regex' => 'A DTI certificate number is digits only.',
            'registration_document.required' => 'Upload the new DTI Business Name Certificate.',
        ];
    }
}
