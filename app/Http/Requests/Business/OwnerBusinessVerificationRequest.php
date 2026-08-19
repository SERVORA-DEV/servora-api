<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class OwnerBusinessVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * registered_owner_name is only meaningful for a Sole Proprietorship
     * (compared against the owner's verified identity — spec §6);
     * authorized_representative_name only for Corporation/Partnership (spec
     * §7). The registration document accepts images and PDF, unlike the
     * identity documents (real DTI/SEC certificates are often issued as
     * PDFs).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business_type' => 'required|string|in:Sole Proprietorship,Corporation,Partnership',

            'registration_document' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:'.(int) config('uploads.document_max_size_kb', 8192),

            'registered_business_name' => 'required|string|max:150',
            'registration_number' => 'required|string|max:100',

            'registered_owner_name' => 'required_if:business_type,Sole Proprietorship|nullable|string|max:150',
            'authorized_representative_name' => 'required_if:business_type,Corporation,Partnership|nullable|string|max:150',
        ];
    }
}
