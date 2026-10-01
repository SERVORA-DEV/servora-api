<?php

namespace App\Http\Requests\Business;

use App\Models\CustomerProgram;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Shape only — the per-type rules (a voucher needs a deal and a condition,
// a discount an amount, …) are in CustomerProgramService::problem().
class CustomerProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'type' => [$isCreate ? 'required' : 'prohibited', Rule::in(CustomerProgram::TYPES)],
            'name' => [$isCreate && $this->input('type') !== 'loyalty' ? 'required' : 'sometimes', 'string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
            'audience' => ['sometimes', Rule::in(CustomerProgram::AUDIENCES)],
            'values' => ['sometimes', 'array'],
            'values.*' => ['nullable', 'max:500'],
            'condition' => ['sometimes', 'nullable', 'array'],
            'condition.kind' => ['nullable', Rule::in(CustomerProgram::CONDITIONS)],
            'condition.value' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'includes' => ['sometimes', 'array'],
            'includes.voucher_uuids' => ['array'],
            'includes.voucher_uuids.*' => ['uuid'],
            'includes.discount_uuids' => ['array'],
            'includes.discount_uuids.*' => ['uuid'],
            'branch_uuids' => ['sometimes', 'array'],
            'branch_uuids.*' => ['uuid'],
        ];
    }
}
