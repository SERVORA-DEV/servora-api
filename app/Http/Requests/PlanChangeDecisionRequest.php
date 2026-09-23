<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlanChangeDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => 'required|string|in:accept,decline',
        ];
    }
}
