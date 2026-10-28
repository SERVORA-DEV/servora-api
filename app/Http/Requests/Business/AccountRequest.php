<?php

namespace App\Http\Requests\Business;

use Illuminate\Validation\Rules\Password;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\NoEmoji;

// Validates POST/PATCH /business/account. An account is a standalone
// Manager/Front Officer login tied to a specific Staff record (see
// AccountService::createAccount) — credentials and the target staff member
// are collected here; role and branch are both derived server-side from
// that staff member, never accepted from the client.
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
            'username' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:50', Rule::unique('users', 'username'), new NoEmoji()],
            'email' => [$isCreate ? 'required' : 'sometimes', 'email', 'max:255', Rule::unique('users', 'email')->where('audience', 'web'), new NoEmoji()],

            // Which staff member this login belongs to — locked at
            // creation, same as role below, since reassigning an account to
            // a different employee isn't a supported operation. Business
            // ownership, eligibility (manager/frontdesk only), and the
            // one-account-per-staff rule are all enforced in
            // AccountService::createAccount, not here.
            // The account's own name.
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100', new NoEmoji()],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100', new NoEmoji()],

            // Where it works and at which level (AccountService checks the
            // branches are the owner's own). Before that migration, a login is
            // made from an employee instead (staff_uuid).
            'branches' => [\App\Models\AccountBranch::ready() && $isCreate ? 'required' : 'sometimes', 'array', 'min:1', 'max:50'],
            'branches.*.branch_uuid' => ['required', 'uuid'],
            'branches.*.level' => ['required', Rule::in(\App\Models\AccountBranch::LEVELS)],
            'staff_uuid' => [$isCreate && ! \App\Models\AccountBranch::ready() ? 'required' : 'prohibited', 'uuid', 'exists:staff,uuid'],

            // Never client-settable — derived from the staff member's job
            // role in AccountService::createAccount. Still validated as
            // prohibited (not just omitted) so a client can never smuggle a
            // role in on create or update.
            'role' => ['prohibited'],

            // Fully derived via staff.branch now — never accepted from the
            // client, on create or update.
            'spa_branch_uuid' => ['prohibited'],

            'password' => [$isCreate ? 'required' : 'nullable', 'string', Password::defaults(), new NoEmoji()],

            'account_status' => ['sometimes', Rule::in(['Active', 'Inactive', 'Suspended'])],

            // Module-level access toggles — see
            // AccountRepository::updateAccount, which filters this down to
            // whichever flags are actually in the account's role bundle.
            'permission' => ['sometimes', 'array'],
            'permission.*' => ['boolean'],
        ];
    }
}
