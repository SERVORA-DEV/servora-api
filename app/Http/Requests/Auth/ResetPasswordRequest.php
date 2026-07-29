<?php

namespace App\Http\Requests\Auth;

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

            'reset_token' => [
                'required',
                'string',
            ],

            'password' => [
                'required',
                'confirmed',
                'min:8',
            ],
        ];
    }
}
