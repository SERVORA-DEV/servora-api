<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// GET api/business/checkout/quote (CheckoutController::quote).
class CheckoutQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'extra_branches' => 'sometimes|integer|min:0|max:1000',
            'extra_accounts' => 'sometimes|integer|min:0|max:1000',
            // add_slots: how many extra slots to add to the live plan now.
            'add_branches' => 'sometimes|integer|min:0|max:1000',
            'add_accounts' => 'sometimes|integer|min:0|max:1000',
            'purpose' => 'required|in:subscribe,renew,upgrade,save_method,add_slots',
            'plan_uuid' => 'nullable|uuid',
            'billing_cycle' => 'nullable|in:Monthly,Yearly',
        ];
    }
}
