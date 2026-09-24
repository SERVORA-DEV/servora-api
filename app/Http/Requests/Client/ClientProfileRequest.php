<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validates PATCH /client/profile — the mobile onboarding step where a client
// fills in the name and phone number that registration never collects (see
// RegisterClientRequest: email + password only). `email` is accepted but only
// applied to an account that has none, which no current registration path can
// produce — see ClientProfileService for why it is otherwise immutable.
class ClientProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            // users.phone_number carries a unique index. The whereNull on
            // deleted_at matters: the table is soft-deleting and Rule::unique
            // queries it raw, so without it a deleted account's number stays
            // burned forever.
            'phone_number' => [
                'required',
                'string',
                'max:20',
                Rule::unique('users', 'phone_number')
                    ->ignore($userId)
                    ->whereNull('deleted_at'),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->where('audience', 'mobile')
                    ->ignore($userId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    // Shown to the mobile user as-is — the app lifts errors.<field>[0] straight
    // into the form's inline error text (see AuthApi._errorMessage).
    public function attributes(): array
    {
        return [
            'first_name' => 'first name',
            'last_name' => 'last name',
            'phone_number' => 'mobile number',
        ];
    }

    public function messages(): array
    {
        return [
            'phone_number.unique' => 'That mobile number is already linked to another Servora account.',
            'email.unique' => 'That email is already linked to another Servora account.',
        ];
    }
}
