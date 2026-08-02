<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

class BranchRegistrationRejectRequest extends FormRequest
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
