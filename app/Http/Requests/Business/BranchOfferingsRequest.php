<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// Validates PATCH /business/branch/{uuid}/offerings — every service and
// package change for one branch in one request (only the changed rows are
// sent). The per-row fields are BranchAvailabilityRequest's.
//
// Whether the branch, the services and the packages belong to the caller is
// checked in BranchOfferingService, not here.
class BranchOfferingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'services' => 'present|array|max:500',
            'services.*.variant_uuid' => 'required|uuid|distinct',
            'services.*.is_available' => 'required|boolean',
            'services.*.custom_price' => 'nullable|numeric|min:0',

            'packages' => 'present|array|max:500',
            'packages.*.package_uuid' => 'required|uuid|distinct',
            'packages.*.is_available' => 'required|boolean',
            'packages.*.custom_price' => 'nullable|numeric|min:0',
        ];
    }
}
