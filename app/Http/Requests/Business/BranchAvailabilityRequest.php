<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// Validates PATCH /business/service/{uuid}/branches and
// /business/package/{uuid}/branches — same payload shape for both, so one
// request class covers them. Branch ownership itself isn't checked here
// (branch_uuid only needs to exist at all) — ServiceService/PackageService
// re-resolve each uuid against the caller's own accessible branches and 404
// otherwise, same split of responsibility FacilityRequest/FacilityService use.
class BranchAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branches' => 'required|array|min:1',
            'branches.*.branch_uuid' => 'required|uuid|exists:spa_branches,uuid',
            'branches.*.is_available' => 'required|boolean',
            'branches.*.custom_price' => 'nullable|numeric|min:0',
        ];
    }
}
