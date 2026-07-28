<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            // Personal detail
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'suffix' => 'nullable|string|max:20',

            'gender' => ['required', Rule::in(['Male', 'Female'])],
            'birth_date' => 'required|date|before:today',

            'phone_number' => [
                'required',
                'string',
                'max:20',
                Rule::unique('users', 'phone_number')->ignore($userId),
            ],

            'profile_photo' => 'nullable|string',

            // Business detail
            'business_name' => 'required|string|max:150',
            'business_email' => 'required|email|unique:spa_businesses,business_email',
            'business_phone' => 'required|string|max:20',

            'business_logo' => 'nullable|string',
            'business_description' => 'nullable|string',
        ];
    }
}
