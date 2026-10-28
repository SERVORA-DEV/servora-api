<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\NoEmoji;

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
                new NoEmoji(),
            ],

            'audience' => ['sometimes', 'in:web,mobile'],

            'otp' => [
                'required',
                'digits:6',
            ],
        ];
    }
}
