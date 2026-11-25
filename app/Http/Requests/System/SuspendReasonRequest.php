<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

// The reason the administrator must provide when suspending a branch.
class SuspendReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:1000',
        ];
    }
}
