<?php

namespace App\Http\Requests\Business;

use App\Models\CustomerProgram;
use App\Models\ProgramRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validates POST /business/program-requests — a manager asking the owner for
// a new voucher, discount or membership, filled in the way the owner's own
// form fills one (see CustomerProgramRequest).
//
// Shape only: who may ask, and the per-type rules, are checked in
// ProgramRequestService.
class ProgramRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(ProgramRequest::TYPES)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            // Why the branch needs it.
            'note' => ['nullable', 'string', 'max:500'],
        ] + self::programRules();
    }

    /**
     * The parts of a program, as CustomerProgramRequest takes them. Also used
     * when the owner approves a request with their own changes.
     */
    public static function programRules(): array
    {
        return [
            'audience' => ['sometimes', Rule::in(CustomerProgram::AUDIENCES)],
            'values' => ['sometimes', 'array'],
            'values.*' => ['nullable', 'max:4000'],
            'condition' => ['sometimes', 'nullable', 'array'],
            'condition.kind' => ['nullable', Rule::in(CustomerProgram::CONDITIONS)],
            'condition.value' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'includes' => ['sometimes', 'array'],
            'includes.voucher_uuids' => ['array'],
            'includes.voucher_uuids.*' => ['uuid'],
            'includes.discount_uuids' => ['array'],
            'includes.discount_uuids.*' => ['uuid'],
        ];
    }
}
