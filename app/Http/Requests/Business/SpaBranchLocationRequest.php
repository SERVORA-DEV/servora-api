<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class SpaBranchLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The Location step of the branch wizard — replaces the old
     * SpaBranchRegistrationRequest (lat/lng only, no address). The owner
     * never types an address anywhere: formatted_address is Nominatim's
     * display_name for the confirmed pin. Saving location never changes
     * verification_status (see SpaBranchService::saveLocation) — only
     * submit() does that, once every step is complete.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'formatted_address' => 'required|string',
        ];
    }
}
