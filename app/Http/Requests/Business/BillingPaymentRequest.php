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
            // Which methods the business accepts, whether a reference is
            // required and the no-overpayment rule are checked against the
            // bill itself in BillingService::paymentRejection.
            'amount' => 'required|numeric|min:0.01',
            // Cash only: what the client handed over (change = this − amount).
            'amount_tendered' => 'nullable|numeric|min:0.01',
            // payments.reference_number is varchar(100).
            'reference_number' => 'nullable|string|max:100',
            'remarks' => 'nullable|string|max:1000',
        ];
    }
}
