<?php

namespace App\Http\Requests\Business\Settings;

use App\Rules\PhMobile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every key is optional so a page can send just what it changed; keys
     * not listed here are dropped by validated() and never stored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'accept_cash' => 'sometimes|boolean',
            'accept_gcash' => 'sometimes|boolean',
            'accept_paymaya' => 'sometimes|boolean',
            'accept_card' => 'sometimes|boolean',
            'accept_bank_transfer' => 'sometimes|boolean',
            // Switched on, the number clients pay to is needed: a PH mobile.
            'gcash_number' => [Rule::requiredIf(fn () => $this->boolean('accept_gcash')), 'nullable', 'string', 'max:20', new PhMobile()],
            'paymaya_number' => [Rule::requiredIf(fn () => $this->boolean('accept_paymaya')), 'nullable', 'string', 'max:20', new PhMobile()],
        ];
    }

    public function messages(): array
    {
        return [
            'gcash_number.required' => 'Add your GCash number, or switch GCash off.',
            'paymaya_number.required' => 'Add your Maya number, or switch Maya off.',
        ];
    }

    public const METHODS = ['accept_cash', 'accept_gcash', 'accept_paymaya', 'accept_card', 'accept_bank_transfer'];

    // Clients need some way to pay. Only checked when the request sends every
    // method — a partial update can't tell what the others are set to.
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $sent = array_intersect_key($this->all(), array_flip(self::METHODS));

            if (count($sent) === count(self::METHODS) && ! array_filter($sent, fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN))) {
                $validator->errors()->add('accept_cash', 'Keep at least one payment method turned on.');
            }
        });
    }
}
