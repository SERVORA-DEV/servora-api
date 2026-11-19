<?php

namespace App\Http\Requests\Business;

use App\Support\Billing\BillAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// POST api/business/billing/{uuid}/adjustments (BillingAdjustmentController::store).
class BillingAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(BillAdjustment::KINDS)],
            'refund_policy_uuid' => ['nullable', 'uuid'],
            'amount' => ['nullable', 'required_without_all:refund_policy_uuid,client_voucher_uuid', 'numeric', 'min:0.01', 'max:1000000'],
            'charge_to' => ['nullable', Rule::in(\App\Support\Billing\RefundCharge::WHO)],
            // Before payment (kind credit): the service it is for; none for the whole bill.
            'service_uuid' => ['nullable', 'uuid'],
            'client_voucher_uuid' => ['nullable', 'required_if:kind,voucher', 'uuid'],
            'reason' => ['nullable', 'required_without:refund_policy_uuid', 'string', 'min:' . BillAdjustment::MIN_REASON, 'max:255'],
        ];
    }
}
