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
            // Needed the first time; after that it can be left out to keep the one on file
            // while only the details are corrected.
            'permit_document' => [
                \Illuminate\Validation\Rule::requiredIf(fn () => ! $this->hasPermitOnFile()),
                'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.(int) config('uploads.document_max_size_kb', 8192),
            ],

            // No national format: each city or municipality numbers its own ("2026-00123",
            // "BP-2026-00123"). Letters, digits and single dashes, 4 to 20 long, starting and
            // ending with a letter or digit; it holds digits, and isn't one long run of digits
            // or one character repeated. Mirrors app/utils/registrationNumbers.ts on the site.
            'permit_number' => ['required', 'string', 'min:4', 'max:20', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?!-))*[A-Za-z0-9]$/',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $v = (string) $value;
                    if (preg_match_all('/\d/', $v) < 3) $fail('A permit number includes its digits, e.g. BP-2026-00123.');
                    elseif (preg_match('/\d{13,}/', $v)) $fail('That\'s too many digits in a row. Copy it exactly as printed, with its dashes.');
                    elseif (preg_match('/^(.)\1+$/', str_replace('-', '', $v))) $fail('That doesn\'t look like a real permit number.');
                },
            ],
            'permit_business_name' => 'required|string|max:255',
            'permit_branch_location' => 'required|string',

            'permit_issue_date' => 'required|date',

            // The owner's "The information provided is accurate" checkbox —
            // required and must be checked, not just present.
            'permit_confirmed' => 'required|accepted',
        ];
    }

    /** The branch in the address already has a permit document saved. */
    private function hasPermitOnFile(): bool
    {
        $uuid = $this->route('uuid') ?? collect($this->route()?->parameters() ?? [])->first();

        return is_string($uuid) && \App\Models\SpaBranch::where('uuid', $uuid)->whereNotNull('permit_document_path')->exists();
    }

    public function messages(): array
    {
        return [
            'permit_number.regex' => 'Use letters, numbers and single dashes, as printed on the permit.',
            'permit_number.max' => 'A permit number is 20 characters at most.',
            'permit_number.min' => 'Too short: a permit number has at least 4 characters.',
        ];
    }
}
