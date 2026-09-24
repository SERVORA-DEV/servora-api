<?php

namespace App\Http\Requests\Business\Settings;

use App\Repository\SpaBusinessRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BusinessIdentityRequest extends FormRequest
{
    public const SPA_TYPES = ['Day Spa', 'Health Spa', 'Medical Spa', 'Resort Spa', 'Beauty Salon', 'Wellness Center'];

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
            'business_name' => 'required|string|max:150',
            'legal_name' => 'nullable|string|max:150',
            'spa_type' => ['nullable', 'string', Rule::in(self::SPA_TYPES)],
            'tagline' => 'nullable|string|max:160',
            'business_description' => 'nullable|string|max:2000',

            'business_email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('spa_businesses', 'business_email')->ignore($business?->id),
            ],
            // Same E.164 format verification onboarding stores.
            'business_phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'head_office_address' => 'nullable|string|max:255',
            'facebook_url' => 'nullable|string|max:255',
            'instagram_handle' => 'nullable|string|max:100',
            'website_url' => 'nullable|string|max:255',

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
            'business_phone.regex' => 'Use the international format, e.g. +639171234567.',
        ];
    }
}
