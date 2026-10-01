<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// POST business/checkout — see CheckoutService::start. The billing address is
// what the checkout shows and the invoice is issued to.
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purpose' => 'required|in:subscribe,renew,upgrade,save_method',
            'plan_uuid' => 'required_unless:purpose,save_method|nullable|uuid|exists:subscription_plans,uuid',
            'billing_cycle' => 'nullable|in:Monthly,Yearly',
            'method' => 'required|in:new,saved',
            'payment_method_uuid' => 'required_if:method,saved|nullable|uuid',
            'save_method' => 'sometimes|boolean',
            'enable_auto_renew' => 'sometimes|boolean',

            'billing_address' => 'required|array',
            'billing_address.full_name' => 'required|string|max:150',
            'billing_address.email' => 'required|email|max:150',
            'billing_address.phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,}$/'],
            'billing_address.line1' => 'required|string|max:200',
            'billing_address.line2' => 'nullable|string|max:200',
            'billing_address.city' => 'required|string|max:100',
            'billing_address.province' => 'nullable|string|max:100',
            'billing_address.postal_code' => 'required|string|max:20',
            'billing_address.country' => 'required|string|size:2',
            'billing_address.is_business_purchase' => 'sometimes|boolean',
            'billing_address.business_name' => 'required_if_accepted:billing_address.is_business_purchase|nullable|string|max:150',
            'billing_address.tin' => ['nullable', 'string', 'max:30', 'regex:/^[0-9\-\s]{9,20}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'billing_address.full_name.required' => 'Enter the billing name.',
            'billing_address.line1.required' => 'Enter the street address.',
            'billing_address.city.required' => 'Enter the city.',
            'billing_address.postal_code.required' => 'Enter the postal code.',
            'billing_address.business_name.required_if_accepted' => 'Enter the registered business name.',
            'billing_address.tin.regex' => 'Enter the TIN as digits, e.g. 123-456-789-000.',
        ];
    }
}
