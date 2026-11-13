<?php

namespace App\Http\Requests\Business;

use App\Rules\PersonName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\NoEmoji;
use App\Rules\PhMobile;
use App\Support\Gender;

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
            'first_name' => ['required', 'string', 'max:100', new NoEmoji(), new PersonName()],
            'middle_name' => ['nullable', 'string', 'max:100', new NoEmoji(), new PersonName()],
            'last_name' => ['required', 'string', 'max:100', new NoEmoji(), new PersonName()],
            'suffix' => ['nullable', 'string', 'max:20', new NoEmoji(), new PersonName()],

            'gender' => ['required', Rule::in(Gender::CHOICES)],
            'birth_date' => 'required|date|before:today',

            // Optional: a phone number is no part of the verification.
            'phone_number' => [
                'nullable',
                'string',
                new PhMobile(),
                Rule::unique('users', 'phone_number')->ignore($userId),
                new NoEmoji(),
            ],

            'profile_photo' => 'nullable|string',
        ];
    }

    // An old 'Other' reads as "Prefer not to say" (App\Support\Gender).
    protected function prepareForValidation(): void
    {
        if ($this->filled('gender')) {
            $this->merge(['gender' => Gender::forUsers($this->input('gender'))]);
        }
    }

    public function messages(): array
    {
        return [
            'gender.in' => 'Choose Male or Female.',
        ];
    }
}
