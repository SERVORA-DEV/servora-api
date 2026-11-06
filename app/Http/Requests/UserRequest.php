<?php

namespace App\Http\Requests;

use App\Rules\ValidEmail;
use App\Rules\PersonName;
use App\Rules\NoEmoji;
use App\Rules\PhMobile;
use App\Support\Gender;
use Illuminate\Validation\Rules\Password;

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
                new NoEmoji(),
                Rule::unique('users', 'username')->ignore($userUuid, 'uuid'),
            ],

            'first_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100', new NoEmoji(), new PersonName()],
            'middle_name' => ['nullable', 'string', 'max:100', new NoEmoji(), new PersonName()],
            'last_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:100', new NoEmoji(), new PersonName()],
            'suffix' => ['nullable', 'string', 'max:20', new NoEmoji(), new PersonName()],

            'gender' => [$isCreate ? 'required' : 'sometimes', Rule::in(Gender::CHOICES)],
            'birth_date' => [$isCreate ? 'required' : 'sometimes', 'date', 'before:today'],

            'phone_number' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                new NoEmoji(),
                new PhMobile(),
                Rule::unique('users', 'phone_number')->ignore($userUuid, 'uuid'),
            ],

            'email' => [
                $isCreate ? 'required' : 'sometimes',
                'email',
                new ValidEmail(),
                new NoEmoji(),
                Rule::unique('users', 'email')
                    ->where('audience', 'web')
                    ->ignore($userUuid, 'uuid'),
            ],

            'password' => [
                $isCreate ? 'required' : 'nullable',
                'string',
                new NoEmoji(),
                Password::defaults(),
            ],

            'profile_photo' => 'nullable|string',

            'account_status' => [
                'sometimes',
                Rule::in(['Pending', 'Active', 'Inactive', 'Suspended']),
            ],

            ...$permissionRules,
        ];
    }

    // An old 'Other' reads as "Prefer not to say" (App\Support\Gender).
    protected function prepareForValidation(): void
    {
        if ($this->filled('gender')) {
            $this->merge(['gender' => Gender::forUsers($this->input('gender'))]);
        }
    }

    public function messages(): array
    {
        return [
            'gender.in' => 'Choose Male, Female or Prefer not to say.',
        ];
    }
}