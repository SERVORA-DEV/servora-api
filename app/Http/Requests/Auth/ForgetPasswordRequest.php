<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\NoEmoji;

class ForgetPasswordRequest extends FormRequest
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

            // Which account family the email belongs to; defaults to web.
            'audience' => ['sometimes', 'in:web,mobile'],
        ];
    }
}
