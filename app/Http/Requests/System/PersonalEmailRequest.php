<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PersonalEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'personal_email' => [
                'required',
                'email',
                // Must be a different address from the admin's own sign-in
                // email — this is a separate recovery/verification contact,
                // not a duplicate of the account email.
                'different:email',
                Rule::unique('users', 'personal_email')->ignore($this->user()->uuid, 'uuid'),
            ],
        ];
    }

    // `different:email` compares against an `email` input field, which this
    // request never actually receives from the client — inject the caller's
    // own account email so the rule has something real to compare against.
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->user()->email,
        ]);
    }
}
