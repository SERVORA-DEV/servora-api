<?php

namespace App\Http\Requests\Business;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SpaBranchUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Partial-update rules — everything is "sometimes" so this same endpoint
     * serves both a full Edit Branch form submit and a status-only PATCH
     * (Activate/Deactivate just sends operating_status, nothing else).
     * "sometimes|required" means: skip validation when the key is absent
     * (the status-only PATCH), but if it IS present it can't be blank (the
     * full edit form always sends it) — same required set as
     * SpaBranchRequest, only email/phone_number/description stay optional.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_name' => 'sometimes|required|string|max:150',

            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:20',

            'address' => 'sometimes|required|string',

            'city' => 'sometimes|required|string|max:100',
            'province' => 'sometimes|required|string|max:100',
            'postal_code' => 'sometimes|required|string|max:10',

            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',

            'description' => 'nullable|string',

            'operating_status' => 'sometimes|in:Active,Inactive,Temporarily Closed',
        ];
    }
}
