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
            'staff_uuid' => ['required', 'uuid', 'exists:staff,uuid'],

            // role_branch -> "manager.mandug" (login role + the branch's
            // owner-typed code); name -> "juan.delacruz" (the staff member's
            // own first/last name).
            'format' => ['required', Rule::in(['role_branch', 'name'])],
        ];
    }
}
