<?php

namespace App\Http\Requests\Business;

use App\Http\Requests\Business\ProgramRequestRequest;
use Illuminate\Foundation\Http\FormRequest;

// POST api/business/program-requests/{uuid}/approve (ProgramRequestController::approve).
class ProgramRequestApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'min:2', 'max:120']] + ProgramRequestRequest::programRules();
    }
}
