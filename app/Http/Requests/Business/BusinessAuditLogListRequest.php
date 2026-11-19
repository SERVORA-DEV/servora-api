<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// GET api/business/audit-log (BusinessAuditLogController::index).
class BusinessAuditLogListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch' => 'nullable|uuid',
            'area' => 'nullable|string|max:20',
            'person' => 'nullable|uuid',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'q' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:10|max:200',
            'page' => 'nullable|integer|min:1',
        ];
    }
}
