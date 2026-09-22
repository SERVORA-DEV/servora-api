<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

// Shared by BusinessController::suspend and BranchController::suspend — the
// shape is identical to BranchRegistrationRejectRequest, so one class
// covers both instead of duplicating it.
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
