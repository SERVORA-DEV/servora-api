<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// PATCH api/business/billing/{uuid}/program-discount (BillingController::programDiscount).
class BillProgramDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'discount_program_uuid' => ['nullable', 'uuid', 'required_without_all:client_voucher_uuid,remove'],
            'client_voucher_uuid' => ['nullable', 'uuid'],
            'remove' => ['nullable', 'boolean'],
        ];
    }
}
