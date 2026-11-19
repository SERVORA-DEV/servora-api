<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/billing/{uuid}/refund (BillingController::refund).
class BillRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'reason' => ['required', 'string', 'max:255'],
            // The money for one service of the visit, when it is not for the whole bill.
            'service_uuid' => ['nullable', 'string', 'max:64'],
        ];
    }
}
