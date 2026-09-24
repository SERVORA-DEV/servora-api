<?php

namespace App\Http\Requests\Business\Settings;

use Illuminate\Foundation\Http\FormRequest;

class BusinessLegalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'registration_document_type' => 'required|in:DTI,SEC',
            'registration_number' => 'nullable|string|max:100',
            'registered_business_name' => 'nullable|string|max:255',
            'registered_owner_name' => 'nullable|string|max:255',
            'authorized_representative_name' => 'nullable|string|max:255',
        ];
    }
}
