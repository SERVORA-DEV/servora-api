<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/reports/export-authorize (PlanAccessController::authorizeExport).
class ExportAuthorizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['format' => 'required|in:csv,pdf,excel'];
    }
}
