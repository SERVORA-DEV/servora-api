<?php

namespace App\Http\Requests\Client;

use Illuminate\Validation\Rules\Password;

use Illuminate\Foundation\Http\FormRequest;

class ClientChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ];
    }
}
