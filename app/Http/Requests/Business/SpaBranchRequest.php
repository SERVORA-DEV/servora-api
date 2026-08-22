<?php

namespace App\Http\Requests\Business;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SpaBranchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * spa_business_id is deliberately absent — it's derived server-side from
     * the authenticated owner (see SpaBranchService::createSpaBranch), never
     * trusted from the request body. The owner never types an address at
     * all; it's derived from the map pin in the Location step instead (see
     * SpaBranchLocationRequest/SpaBranchService::saveLocation). Only email,
     * phone_number, description, and cover_photo are optional.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_name' => 'required|string|max:150',

            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:20',

            'description' => 'nullable|string',

            // Display/identification only — never treated as verification
            // evidence (see PermitStep/SpaBranchPermitRequest for that).
            'cover_photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:'.(int) config('uploads.max_size_kb', 5120),
        ];
    }
}
