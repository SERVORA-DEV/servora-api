<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'otp' => [
                'required',
                'digits:6',
            ],
        ];
    }
}
