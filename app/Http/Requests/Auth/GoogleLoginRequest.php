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
            // The ID token (a JWT) Google hands the phone app, or an access token from the
            // web app's own button: one of the two is needed.
            'credential' => ['required_without:access_token', 'nullable', 'string', 'max:4096'],
            'access_token' => ['required_without:credential', 'nullable', 'string', 'max:4096'],
            // Sent by the sign-up page only. Signing in to an existing account
            // doesn't need it; creating one does (UserService::loginWithGoogle).
            'terms_accepted' => ['sometimes', 'boolean'],
        ];
    }
}
