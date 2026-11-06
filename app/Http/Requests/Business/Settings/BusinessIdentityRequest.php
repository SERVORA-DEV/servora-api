<?php

namespace App\Http\Requests\Business\Settings;

use App\Rules\NoEmoji;
use App\Rules\ValidEmail;
use App\Repository\SpaBusinessRepository;
use App\Rules\PhMobile;
use App\Rules\SocialLink;
use App\Support\SocialLinks;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BusinessIdentityRequest extends FormRequest
{
    public const SPA_TYPES = ['Day Spa', 'Health Spa', 'Resort Spa', 'Wellness Center'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Sent as multipart (POST) because the logo rides along with the text
     * fields — one Save button, one request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $business = app(SpaBusinessRepository::class)->findForUser($this->user());

        return [
            'business_name' => ['required', 'string', 'max:150', new NoEmoji()],
            'legal_name' => ['nullable', 'string', 'max:150', new NoEmoji()],
            'spa_type' => ['nullable', 'string', Rule::in(self::SPA_TYPES)],
            'tagline' => 'nullable|string|max:160',
            'business_description' => 'nullable|string|max:2000',

            'business_email' => [
                'required',
                'email',
                new ValidEmail(),
                'max:255',
                Rule::unique('spa_businesses', 'business_email')->ignore($business?->id),
            ],
            // A PH mobile, sent as +639XXXXXXXXX (the site's phone field).
            'business_phone' => ['nullable', 'string', 'max:20', new PhMobile()],
            'head_office_address' => 'nullable|string|max:255',
            // Company-wide links; a branch shows these for any it hasn't set
            // itself (App\Support\SocialLinks).
            'facebook_url' => ['nullable', 'string', 'max:255', new SocialLink(SocialLinks::FACEBOOK)],
            'instagram_handle' => ['nullable', 'string', 'max:100', new SocialLink(SocialLinks::INSTAGRAM)],
            'website_url' => ['nullable', 'string', 'max:255', new SocialLink(SocialLinks::WEBSITE)],

            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:'.(int) config('uploads.max_size_kb', 5120),
            'remove_logo' => 'sometimes|boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_email.unique' => 'Another business already uses that email.',
        ];
    }
}
