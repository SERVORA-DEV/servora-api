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
     * trusted from the request body. Latitude/longitude are validated only
     * when present; picking them on a map is a separate feature, not a
     * requirement for registering a branch. Only email, phone_number, and
     * description are optional — everything else about where/what the
     * branch is must be filled in.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_name' => 'required|string|max:150',

            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:20',

            'address' => 'required|string',

            'city' => 'required|string|max:100',
            'province' => 'required|string|max:100',
            'postal_code' => 'required|string|max:10',

            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',

            'description' => 'nullable|string',
        ];
    }
}
