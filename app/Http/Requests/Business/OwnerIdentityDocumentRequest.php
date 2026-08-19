<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

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
     * optional. Both sides are required for every ID type, Passport
     * included — keeps the rule simple and uniform rather than branching on
     * id_type (a Passport's back is just its inside cover, but requiring it
     * consistently avoids special-casing the validation and the review UI).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxSizeKb = (int) config('uploads.document_max_size_kb', 8192);

        return [
            'id_type' => 'required|string|in:Philippine National ID,Passport,Drivers License,UMID,Other',
            'id_document_front' => "required|file|mimes:jpg,jpeg,png,webp|max:{$maxSizeKb}",
            'id_document_back' => "required|file|mimes:jpg,jpeg,png,webp|max:{$maxSizeKb}",
        ];
    }
}
