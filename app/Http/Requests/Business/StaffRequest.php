<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validates POST/PATCH /business/staff. email/phone_number are optional —
// only required once this staff member is granted a login account (see
// AccountRequest/AccountService), not for the staff record to exist at all.
// hire_date IS required on create — an HR staff record with no start date
// on file isn't a meaningful record, unlike the contact-info fields above.
class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'employee_number' => ['nullable', 'string', 'max:50', Rule::unique('staff', 'employee_number')->where(fn ($q) => $q->where('spa_branch_id', $this->resolvedBranchId()))->ignore($this->route('staff'), 'uuid')],

            'first_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100'],
            'last_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100'],
            'middle_name' => 'nullable|string|max:100',
            'suffix' => 'nullable|string|max:20',

            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:20',

            'gender' => ['nullable', Rule::in(['Male', 'Female', 'Other'])],
            'birth_date' => 'nullable|date|before:today',
            'hire_date' => [$isCreate ? 'required' : 'sometimes', 'date'],

            'role' => [$isCreate ? 'required' : 'sometimes', Rule::in(['therapist', 'manager', 'frontdesk'])],
            'employment_type' => [$isCreate ? 'required' : 'sometimes', Rule::in(['Full-Time', 'Part-Time', 'Contractual'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'on-leave', 'terminated'])],

            'emergency_contact_name' => 'nullable|string|max:150',
            'emergency_contact_number' => 'nullable|string|max:20',
            'notes' => 'nullable|string',

            'spa_branch_uuid' => [$isCreate ? 'required' : 'sometimes', 'uuid', 'exists:spa_branches,uuid'],
        ];
    }

    // employee_number uniqueness is scoped per-branch (see the staff table's
    // (spa_branch_id, employee_number) unique index) — the branch itself is
    // only known by uuid at validation time, so resolve its id the same way
    // StaffService does before the uniqueness rule can be built.
    private function resolvedBranchId(): ?int
    {
        $uuid = $this->input('spa_branch_uuid');
        if (! $uuid) {
            return null;
        }
        return \App\Models\SpaBranch::where('uuid', $uuid)->value('id');
    }
}
