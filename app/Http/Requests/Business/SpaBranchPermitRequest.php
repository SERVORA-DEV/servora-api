<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class SpaBranchPermitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The Permit step of the branch wizard — the branch's own Business/
     * Mayor's Permit (proof this specific location is a legitimate branch
     * of the already-verified business). Deliberately does not re-ask for
     * BIR/DTI/SEC/barangay clearance — the business itself already went
     * through that at the account level (see OwnerVerificationService).
     * permit_document allows the same document mimes as the business
     * registration document (config('uploads.document_*') — see
     * DocumentUploadService), since a permit may be a scanned PDF.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permit_document' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:'.(int) config('uploads.document_max_size_kb', 8192),

            'permit_number' => 'required|string|max:100',
            'permit_business_name' => 'required|string|max:255',
            'permit_branch_location' => 'required|string',

            'permit_issue_date' => 'required|date',
            'permit_expiration_date' => 'required|date|after:permit_issue_date',

            // The owner's "The information provided is accurate" checkbox —
            // required and must be checked, not just present.
            'permit_confirmed' => 'required|accepted',
        ];
    }
}
