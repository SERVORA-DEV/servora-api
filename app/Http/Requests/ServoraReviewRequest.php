<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// PUT api/business/servora-review (ServoraReviewController::save).
class ServoraReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => 'required|integer|between:1,5',
            'title' => 'nullable|string|max:80',
            'body' => 'required|string|min:20|max:1200',
            'show_business' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'body.min' => 'Write a little more: at least 20 characters.',
        ];
    }
}
