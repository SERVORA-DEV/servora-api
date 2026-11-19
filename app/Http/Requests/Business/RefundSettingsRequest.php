<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// PATCH api/business/refund-policies/settings (RefundPolicyController::settings).
class RefundSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['allow_custom' => ['required', 'boolean']];
    }
}
