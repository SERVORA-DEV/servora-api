<?php

namespace App\Http\Requests\Auth;

use App\Rules\NoEmoji;
use Illuminate\Foundation\Http\FormRequest;

// POST api/auth/login, POST api/auth/admin/login (AuthController::login, adminLogin).
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // The sign-in's two credentials are held to the same no-emoji rule as the forms that set
        // them, and must be text (an array used to crash the sign-in). A missing one is left to
        // SignInService, which answers "Enter your email or username, and your password." Everything
        // else (a wrong password, a locked account) is its answer too.
        return [
            'email' => ['bail', 'nullable', 'string', new NoEmoji()],
            'password' => ['bail', 'nullable', 'string', new NoEmoji()],
            'audience' => ['nullable', 'string'],
        ];
    }
}
