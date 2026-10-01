<?php

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rules\Password;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
            ],

            'audience' => ['sometimes', 'in:web,mobile'],

            'reset_token' => [
                'required',
                'string',
            ],

            'password' => [
                'required',
                'confirmed',
                Password::defaults(),
            ],
        ];
    }
}
