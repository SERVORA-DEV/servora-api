<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Which staff member the suggested login would belong to — the
            // only input the generator needs, since role and branch are both
            // read off that staff member (same derivation
            // AccountService::createAccount uses). Business ownership is
            // enforced in the service, not here, exactly as AccountRequest
            // documents at its own staff_uuid rule.
            // The name and first branch typed so far (or, before logins were
            // given branches, the employee the login is for).
            'staff_uuid' => [\App\Models\AccountBranch::ready() ? 'nullable' : 'required', 'uuid', 'exists:staff,uuid'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'branch_uuid' => ['nullable', 'uuid'],
            'level' => ['nullable', Rule::in(\App\Models\AccountBranch::LEVELS)],

            // role_branch -> "manager.mandug" (login role + the branch's
            // owner-typed code); name -> "juan.delacruz" (the staff member's
            // own first/last name).
            'format' => ['required', Rule::in(['role_branch', 'name'])],
        ];
    }
}
