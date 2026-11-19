<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// POST api/business/programs/memberships/{uuid}/renew (CustomerProgramController::renewMembership):
// how a membership is paid for. Same as BillingPaymentRequest; the amount is the membership's price.
// SellMembershipRequest adds who it is for.
class MembershipPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::in(['Cash', 'GCash', 'Maya', 'Bank Transfer', 'Online Banking', 'Credit Card', 'Debit Card', 'QR Code', 'Other'])],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
