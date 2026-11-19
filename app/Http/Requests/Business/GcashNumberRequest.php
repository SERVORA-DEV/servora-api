<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/payment-methods/gcash (CheckoutController::addGcash).
class GcashNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['mobile_number' => ['required', 'string', 'max:20', new \App\Rules\PhMobile()]];
    }
}
