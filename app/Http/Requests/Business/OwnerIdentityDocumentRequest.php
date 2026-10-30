<?php

namespace App\Http\Requests\Business;

use App\Models\OwnerIdentityVerification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OwnerIdentityDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Backs both the first-time upload and the "replace the rejected
     * document" flow — the same form is resubmitted either way (see
     * OwnerVerificationService::saveIdentityDocument), so fresh files are
     * always required rather than trying to make them conditionally
     * optional. Both sides are required for every ID type except a Passport,
     * which has no back (OwnerIdentityVerification::backRequired); one sent
     * anyway is still checked and kept.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxSizeKb = (int) config('uploads.document_max_size_kb', 8192);

        return [
            'id_type' => 'required|string|in:Philippine National ID,Passport,Drivers License,UMID,Other',
            'id_document_front' => "required|file|mimes:jpg,jpeg,png,webp|max:{$maxSizeKb}",
            'id_document_back' => [
                Rule::requiredIf(fn () => OwnerIdentityVerification::backRequired($this->input('id_type'))),
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp',
                "max:{$maxSizeKb}",
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'id_document_back.required' => 'Upload the back of your ID too. Only a passport is a single photo.',
        ];
    }
}
