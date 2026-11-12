<?php

namespace App\Http\Requests\Business;

use App\Rules\ValidEmail;
use App\Rules\SocialLink;
use App\Support\SocialLinks;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\NoEmoji;
use App\Rules\PhMobile;

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
            'branch_name' => ['sometimes', 'required', 'string', 'max:150', new NoEmoji()],

            // The area this branch is in ("Mandug"). Not unique on its own: what tells branches apart
            // is the name and the area together (App\Support\BranchIdentity, checked in after()).
            'code' => ['nullable', 'string', 'max:50'],

            'email' => ['nullable', 'email', new ValidEmail(), 'max:255', new NoEmoji()],
            'phone_number' => ['nullable', 'string', 'max:20', new NoEmoji(), new PhMobile()],

            // Branch Settings → Branch Details → Social links.
            // Each branch's own links; any left empty show the company's
            // (App\Support\SocialLinks).
            'facebook_url' => ['nullable', 'string', 'max:255', new SocialLink(SocialLinks::FACEBOOK)],
            'instagram_handle' => ['nullable', 'string', 'max:100', new SocialLink(SocialLinks::INSTAGRAM)],
            'website_url' => ['nullable', 'string', 'max:255', new SocialLink(SocialLinks::WEBSITE)],

            'description' => 'nullable|string',

            'cover_photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:'.(int) config('uploads.max_size_kb', 5120),

            'operating_status' => 'sometimes|in:Active,Inactive,Temporarily Closed',
        ];
    }

    /**
     * @return array<string, string>
     */
    /** The same name in the same area as another branch of the business (only when the name or area is sent). */
    public function after(): array
    {
        return [function ($validator) {
            if (! $this->hasAny(['branch_name', 'code']) || $validator->errors()->has('branch_name') || $validator->errors()->has('code')) {
                return;
            }
            $uuid = (string) $this->route('branch');
            $existing = \App\Models\SpaBranch::where('uuid', $uuid)->first(['branch_name', 'code']);
            $name = $this->has('branch_name') ? $this->input('branch_name') : $existing?->branch_name;
            $area = $this->has('code') ? $this->input('code') : $existing?->code;
            if (\App\Support\BranchIdentity::taken($this->resolvedBusinessId(), $name, $area, $uuid)) {
                $validator->errors()->add('branch_name', \App\Support\BranchIdentity::message($area));
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
