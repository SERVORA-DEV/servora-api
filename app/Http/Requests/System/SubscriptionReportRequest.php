<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

// GET api/system/reports/subscriptions (SubscriptionReportController::__invoke).
class SubscriptionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section' => 'required|in:subscriptions,payments',
            'per_page' => 'sometimes|integer|min:1|max:250', 'page' => 'sometimes|integer|min:1',
        ];
    }
}
