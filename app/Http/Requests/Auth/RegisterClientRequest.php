<?php

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rules\Password;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterClientRequest extends FormRequest
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
                // Only a VERIFIED account with this email blocks
                // registration — an existing unverified/pending row is
                // allowed through, so resubmitting after a lost/expired
                // OTP works as a resend instead of failing as "taken".
                Rule::unique('users', 'email')->where(
                    fn ($query) => $query
                        ->where('audience', 'mobile')
                        ->whereNotNull('email_verified_at')
                ),
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
