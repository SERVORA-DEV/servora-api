<?php

namespace App\Http\Requests\Business;

use App\Rules\NoEmoji;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// PATCH /business/account/profile: what an owner, manager or front officer can
// change about their own account themselves. Only the phone number: the name
// was checked in identity verification (owner) or belongs to the owner's staff
// record (staff), and the email is what they sign in with.
class AccountProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // users.phone_number carries a unique index. The whereNull matters:
            // the table is soft-deleting and Rule::unique queries it raw, so
            // without it a deleted account's number stays burned forever.
            'phone_number' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('users', 'phone_number')->ignore($this->user()?->id)->whereNull('deleted_at'),
                new NoEmoji(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_number.unique' => 'That mobile number is already linked to another Servora account.',
        ];
    }
}
