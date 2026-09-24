<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPasswordResetOtpRequest extends FormRequest
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

            'otp' => [
                'required',
                'digits:6',
            ],
        ];
    }
}
