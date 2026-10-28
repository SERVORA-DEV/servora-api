<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\NoEmoji;

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
            'first_name' => ['required', 'string', 'max:100', new NoEmoji()],
            'middle_name' => ['nullable', 'string', 'max:100', new NoEmoji()],
            'last_name' => ['required', 'string', 'max:100', new NoEmoji()],
            'suffix' => ['nullable', 'string', 'max:20', new NoEmoji()],

            'gender' => ['required', Rule::in(['Male', 'Female', 'Prefer not to say'])],
            'birth_date' => 'required|date|before:today',

            // Optional: a phone number is no part of the verification.
            'phone_number' => [
                'nullable',
                'string',
                'regex:/^\+[1-9]\d{6,14}$/',
                Rule::unique('users', 'phone_number')->ignore($userId),
                new NoEmoji(),
            ],

            'profile_photo' => 'nullable|string',
        ];
    }
}
