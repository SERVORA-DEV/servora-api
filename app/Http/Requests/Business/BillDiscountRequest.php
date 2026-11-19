<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// PATCH api/business/billing/{uuid}/discount (BillingController::discount).
class BillDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:amount,percent'],
            'value' => ['required', 'numeric', 'min:0', $this->input('type') === 'percent' ? 'max:100' : 'max:1000000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
