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
     * full edit form always sends it). address/city/province/postal_code
     * are handled by SpaBranchLocationRequest instead (see SpaBranchRequest)
     * — this endpoint never touches location.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_name' => 'sometimes|required|string|max:150',

            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:20',

            'description' => 'nullable|string',

            'cover_photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:'.(int) config('uploads.max_size_kb', 5120),

            'operating_status' => 'sometimes|in:Active,Inactive,Temporarily Closed',
        ];
    }
}
