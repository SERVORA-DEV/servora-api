<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
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
                // Unique per audience: the same email may also hold a client account.
                Rule::unique('users', 'email')->where('audience', 'web'),
            ],

            'password' => [
                'required',
                'confirmed',
                'min:8',
            ],
        ];
    }
}