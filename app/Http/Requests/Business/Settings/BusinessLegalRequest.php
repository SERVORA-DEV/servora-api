<?php

namespace App\Http\Requests\Business\Settings;

use App\Rules\NoEmoji;
use App\Rules\PersonName;
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
            // A DTI certificate number is digits only; a SEC number has letters.
            'registration_number' => ['nullable', 'string', 'max:100', ...($this->input('registration_document_type') === 'DTI' ? ['regex:/^\d+$/'] : [])],
            'registered_business_name' => ['nullable', 'string', 'max:255', new NoEmoji()],
            'registered_owner_name' => ['nullable', 'string', 'max:255', new NoEmoji(), new PersonName()],
            'authorized_representative_name' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'registration_number.regex' => 'A DTI certificate number is digits only.',
        ];
    }
}
