<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

// GET api/system/audit-logs (AuditLogController::index).
class AuditLogListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'in:all,account,moderation,configuration'],
            'actor_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:80'],
            'module' => ['nullable', 'string', 'max:80'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', 'in:newest,oldest'],
        ];
    }
}
