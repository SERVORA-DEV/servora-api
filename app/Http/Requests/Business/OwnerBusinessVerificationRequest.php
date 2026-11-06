<?php

namespace App\Http\Requests\Business;

use App\Rules\NoEmoji;
use App\Rules\PersonName;
use App\Repository\SpaBusinessRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            // Needed the first time. With a certificate already on file, the
            // details can be corrected without uploading it again.
            'registration_document' => [Rule::requiredIf(fn () => ! $this->hasDocumentOnFile()), 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.(int) config('uploads.document_max_size_kb', 8192)],

            'registered_business_name' => ['required', 'string', 'max:150', new NoEmoji()],
            // Sole proprietors register with DTI: the certificate number is digits only.
            'registration_number' => ['required', 'string', 'max:100', ...($this->input('business_type') === 'Sole Proprietorship' ? ['regex:/^\d+$/'] : [])],

            'registered_owner_name' => ['required_if:business_type,Sole Proprietorship', 'nullable', 'string', 'max:150', new NoEmoji(), new PersonName()],
            'authorized_representative_name' => 'required_if:business_type,Corporation,Partnership|nullable|string|max:150',
        ];
    }

    private function hasDocumentOnFile(): bool
    {
        $business = app(SpaBusinessRepository::class)->findForUser($this->user());

        return (bool) $business?->registration_document_path;
    }

    public function messages(): array
    {
        return [
            'registration_number.regex' => 'A DTI certificate number is digits only.',
            'registration_document.required' => 'Upload your DTI Business Name Certificate.',
        ];
    }
}
