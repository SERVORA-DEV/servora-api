<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validates POST/PATCH /business/account. An account is a standalone
// Manager/Front Officer login — no Staff record involved (see
// AccountService::createAccount) — so credentials, role, and branch are
// all collected directly here.
class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'username' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:50', Rule::unique('users', 'username')],
            'email' => [$isCreate ? 'required' : 'sometimes', 'email', 'max:255', Rule::unique('users', 'email')],

            // Locked at creation — changing it later would invalidate the
            // account's permission bundle, so it's rejected outright on
            // update rather than silently ignored (same idiom this class
            // used for staff_uuid before the Staff link was removed).
            'role' => [$isCreate ? 'required' : 'prohibited', Rule::in(['manager', 'front_officer'])],

            // Editable on update too, unlike role — an owner can reassign
            // an account to a different branch without recreating it.
            'spa_branch_uuid' => [$isCreate ? 'required' : 'sometimes', 'uuid', 'exists:spa_branches,uuid'],

            'password' => [$isCreate ? 'required' : 'nullable', 'string', 'min:8'],

            'account_status' => ['sometimes', Rule::in(['Active', 'Inactive', 'Suspended'])],

            // Module-level access toggles — see
            // AccountRepository::updateAccount, which filters this down to
            // whichever flags are actually in the account's role bundle.
            'permission' => ['sometimes', 'array'],
            'permission.*' => ['boolean'],
        ];
    }
}
