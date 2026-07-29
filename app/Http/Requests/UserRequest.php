<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validates POST/PATCH /system/admin/user-management. This endpoint only
// ever creates/updates system_administrator accounts (AdminUsersService
// forces the role), so field requiredness and the permission whitelist
// below are scoped to that role only — mirrors the shape/strictness of
// Owner\OnboardingRequest (same phone regex, same before:today birth_date).
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        // Route wildcard name isn't 'user' (apiResource('admin/user-management', ...)
        // generates its own parameter name) — grab whatever the single route
        // parameter is instead of relying on that name, so ignore() actually works.
        $userUuid = collect($this->route()?->parameters() ?? [])->first();

        $permissionRules = collect(config('permission.system_administrator'))
            ->mapWithKeys(fn (string $field) => [$field => 'sometimes|boolean'])
            ->all();

        return [
            'username' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'max:50',
                Rule::unique('users', 'username')->ignore($userUuid, 'uuid'),
            ],

            'first_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100'],
            'middle_name' => 'nullable|string|max:100',
            'last_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100'],
            'suffix' => 'nullable|string|max:20',

            'gender' => [$isCreate ? 'required' : 'sometimes', Rule::in(['Male', 'Female'])],
            'birth_date' => [$isCreate ? 'required' : 'sometimes', 'date', 'before:today'],

            'phone_number' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                'regex:/^\+[1-9]\d{6,14}$/',
                Rule::unique('users', 'phone_number')->ignore($userUuid, 'uuid'),
            ],

            'email' => [
                $isCreate ? 'required' : 'sometimes',
                'email',
                Rule::unique('users', 'email')->ignore($userUuid, 'uuid'),
            ],

            'password' => [
                $isCreate ? 'required' : 'nullable',
                'string',
                'min:8',
            ],

            'profile_photo' => 'nullable|string',

            'account_status' => [
                'sometimes',
                Rule::in(['Pending', 'Active', 'Inactive', 'Suspended']),
            ],

            ...$permissionRules,
        ];
    }
}