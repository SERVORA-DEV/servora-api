<?php

namespace App\Http\Requests\Business;

use App\Repository\SpaBusinessRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OwnerBusinessDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Business basic info — the first sub-step of the Business Ownership
     * step, run before the business type/registration document sub-step.
     * OwnerVerificationService::saveBusinessDetails creates the SpaBusiness
     * row here if this is the owner's first time through (a fresh account
     * has none yet), or updates it on a later resubmission — the uniqueness
     * check below ignores that same row so re-saving your own unchanged
     * email doesn't false-positive as a duplicate.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $business = app(SpaBusinessRepository::class)->findForUser($this->user());

        return [
            'business_name' => 'required|string|max:150',
            'business_email' => [
                'required',
                'email',
                Rule::unique('spa_businesses', 'business_email')->ignore($business?->id),
            ],
            'business_phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'business_description' => 'nullable|string',
        ];
    }
}
