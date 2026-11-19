<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

// GET api/system/reports/registrations (RegistrationReportController::__invoke).
class RegistrationReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['per_page' => 'sometimes|integer|min:1|max:250', 'page' => 'sometimes|integer|min:1'];
    }
}
