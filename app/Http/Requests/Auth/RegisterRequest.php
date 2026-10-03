<?php

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rules\Password;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return ['terms_accepted.accepted' => 'Please agree to the Terms of Service and Privacy Policy to create an account.'];
    }

    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                // Unique per audience: the same email may also hold a client account.
                Rule::unique('users', 'email')->where('audience', 'web'),
            ],

            'password' => [
                'required',
                'confirmed',
                Password::defaults(),
            ],

            // "I agree to the Terms of Service and Privacy Policy" — the
            // account is stamped with when, and which version (config/legal.php).
            'terms_accepted' => ['accepted'],
        ];
    }
}