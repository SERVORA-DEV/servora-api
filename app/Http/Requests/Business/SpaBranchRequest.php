<?php

namespace App\Http\Requests\Business;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpaBranchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * spa_business_id is deliberately absent — it's derived server-side from
     * the authenticated owner (see SpaBranchService::createSpaBranch), never
     * trusted from the request body. The owner never types an address at
     * all; it's derived from the map pin in the Location step instead (see
     * SpaBranchLocationRequest/SpaBranchService::saveLocation). Only email,
     * phone_number, description, and cover_photo are optional.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_name' => 'required|string|max:150',

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
                    ->whereNull('deleted_at'),
            ],

            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:20',

            'description' => 'nullable|string',

            // Display/identification only — never treated as verification
            // evidence (see PermitStep/SpaBranchPermitRequest for that).
            'cover_photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:'.(int) config('uploads.max_size_kb', 5120),
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
