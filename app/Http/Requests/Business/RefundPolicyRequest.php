<?php

namespace App\Http\Requests\Business;

use App\Rules\NoEmoji;
use App\Support\Billing\RefundCharge;
use App\Support\Billing\RefundPolicyRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// POST api/business/refund-policies, PATCH api/business/refund-policies/{uuid}
// (RefundPolicyController::store, update): one refund policy.
class RefundPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80', new NoEmoji()],
            'description' => ['nullable', 'string', 'max:200'],
            'mode' => ['required', Rule::in(RefundPolicyRule::MODES)],
            'value' => ['nullable', 'numeric', 'min:0', $this->input('mode') === 'percent' ? 'max:100' : 'max:1000000'],
            'allow_less' => ['sometimes', 'boolean'],
            'needs_approval' => ['sometimes', 'boolean'],
            'charge_to' => ['sometimes', Rule::in(RefundCharge::WHO)],
        ];
    }
}
