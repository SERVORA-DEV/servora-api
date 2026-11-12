<?php

namespace App\Http\Requests\Business;

use App\Rules\ValidEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\NoEmoji;
use App\Rules\PhMobile;

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
            'branch_name' => ['required', 'string', 'max:150', new NoEmoji()],

            // The area this branch is in ("Mandug"). Not unique on its own: what tells branches apart
            // is the name and the area together (App\Support\BranchIdentity, checked in after()).
            'code' => ['nullable', 'string', 'max:50'],

            'email' => ['nullable', 'email', new ValidEmail(), 'max:255', new NoEmoji()],
            'phone_number' => ['nullable', 'string', 'max:20', new NoEmoji(), new PhMobile()],

            'description' => 'nullable|string',

            // Display/identification only — never treated as verification
            // evidence (see PermitStep/SpaBranchPermitRequest for that).
            'cover_photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:'.(int) config('uploads.max_size_kb', 5120),
        ];
    }

    /**
     * @return array<string, string>
     */
    /** The same name in the same area as one the business already has. */
    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->has('branch_name') || $validator->errors()->has('code')) {
                return;
            }
            if (\App\Support\BranchIdentity::taken($this->resolvedBusinessId(), $this->input('branch_name'), $this->input('code'))) {
                $validator->errors()->add('branch_name', \App\Support\BranchIdentity::message($this->input('code')));
            }
        }];
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

        return \App\Support\AccountScope::business($user)?->id;
    }
}
