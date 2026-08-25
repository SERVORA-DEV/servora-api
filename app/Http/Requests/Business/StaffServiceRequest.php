<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// Validates PATCH staff/{uuid}/services — replaces a therapist's
// qualifications wholesale (same "edited as a full set" idiom as
// PackageRequest's services array). An empty array is valid and means "no
// restrictions configured" (see StaffServiceRepository::isQualified's
// opt-in-if-configured semantics).
class StaffServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_uuids' => 'sometimes|array',
            'service_uuids.*' => 'uuid|exists:services,uuid',
        ];
    }
}
