<?php

namespace App\Http\Requests\Business\Settings;

use Illuminate\Foundation\Http\FormRequest;

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
            'accept_xendit' => 'sometimes|boolean',
            'accept_bank_transfer' => 'sometimes|boolean',
            'gcash_number' => 'nullable|string|max:20',
            'paymaya_number' => 'nullable|string|max:20',
        ];
    }

    public const METHODS = ['accept_cash', 'accept_gcash', 'accept_paymaya', 'accept_card', 'accept_xendit', 'accept_bank_transfer'];

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
