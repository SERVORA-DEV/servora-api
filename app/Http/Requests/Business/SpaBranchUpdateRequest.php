<?php

namespace App\Http\Requests\Business;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpaBranchUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Partial-update rules — everything is "sometimes" so this same endpoint
     * serves both a full Edit Branch form submit and a status-only PATCH
     * (Activate/Deactivate just sends operating_status, nothing else).
     * "sometimes|required" means: skip validation when the key is absent
     * (the status-only PATCH), but if it IS present it can't be blank (the
     * full edit form always sends it). Location is handled by
     * SpaBranchLocationRequest instead (see SpaBranchRequest) — this
     * endpoint never touches it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_name' => 'sometimes|required|string|max:150',

            // The short area/locality label for this branch ("Mandug") —
            // business-scoped like services.code, matching the
            // unique(spa_business_id, code) index so a duplicate 422s cleanly
            // instead of surfacing a raw SQL error. whereNull('deleted_at')
            // because spa_branches soft-deletes: a deleted branch must not
            // keep its code reserved forever.
            'code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('spa_branches', 'code')
                    ->where(fn ($q) => $q->where('spa_business_id', $this->resolvedBusinessId()))
                    ->whereNull('deleted_at')
                    ->ignore($this->route('branch'), 'uuid'),
            ],

            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:20',

            // Branch Settings → Branch Details → Social links.
            'facebook_url' => 'nullable|string|max:255',
            'instagram_handle' => 'nullable|string|max:100',
            'website_url' => 'nullable|string|max:255',

            'description' => 'nullable|string',

            'cover_photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:'.(int) config('uploads.max_size_kb', 5120),

            'operating_status' => 'sometimes|in:Active,Inactive,Temporarily Closed',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'You already have a branch using that code.',
        ];
    }

    /**
     * A blank code must land in the column as NULL, not '' — the unique index
     * treats multiple NULLs as distinct but would reject a second ''. Guarded
     * on has() so a payload that never mentions `code` stays untouched.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => trim((string) $this->input('code')) ?: null]);
        }
    }

    /**
     * The authenticated user's business id — scopes the code uniqueness check.
     * Same helper as ServiceRequest/PackageRequest.
     */
    private function resolvedBusinessId(): ?int
    {
        $user = $this->user();
        if (! $user) {
            return null;
        }

        if ($user->role === 'business_owner') {
            return \App\Models\SpaBusiness::where('owner_id', $user->id)->value('id');
        }

        return $user->staff?->branch?->business?->id;
    }
}
