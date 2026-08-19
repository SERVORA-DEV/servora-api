<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OwnerPersonalDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Same rules the old (now-removed) OnboardingRequest used for its
     * personal-detail half — just relocated to the first sub-step of the
     * Owner Identity step. No status-machine guard in the service: a user
     * can always correct their own name/contact info, unlike the
     * verification artifacts (ID, face scan) below it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'suffix' => 'nullable|string|max:20',

            'gender' => ['required', Rule::in(['Male', 'Female', 'Prefer not to say'])],
            'birth_date' => 'required|date|before:today',

            'phone_number' => [
                'required',
                'string',
                'regex:/^\+[1-9]\d{6,14}$/',
                Rule::unique('users', 'phone_number')->ignore($userId),
            ],

            'profile_photo' => 'nullable|string',
        ];
    }
}
