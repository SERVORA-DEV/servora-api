<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The ID token (a JWT) Google Identity Services hands the browser.
            'credential' => ['required', 'string', 'max:4096'],
            // Sent by the sign-up page only. Signing in to an existing account
            // doesn't need it; creating one does (UserService::loginWithGoogle).
            'terms_accepted' => ['sometimes', 'boolean'],
        ];
    }
}
