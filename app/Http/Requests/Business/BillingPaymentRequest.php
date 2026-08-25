<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillingPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Mirrors the payments.payment_method enum exactly (widened for
            // Xendit hosted-checkout support — see
            // 2026_07_30_140010_widen_payments_payment_method_enum).
            'payment_method' => ['required', Rule::in([
                'Cash', 'GCash', 'Maya', 'Bank Transfer', 'Online Banking',
                'Credit Card', 'Debit Card', 'QR Code', 'Other',
            ])],
            'amount' => 'required|numeric|min:0.01',
            'reference_number' => 'nullable|string|max:255',
            'remarks' => 'nullable|string',
        ];
    }
}
