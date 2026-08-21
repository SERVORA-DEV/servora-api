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
     * SpaBranchRegistrationRequest (lat/lng only, no address). The owner no
     * longer types an address anywhere: formatted_address is Nominatim's
     * display_name for the confirmed pin, and address/city/province/
     * postal_code are best-effort components the frontend may have parsed
     * from the same reverse-geocode lookup — all optional since not every
     * result carries them. Saving location never changes verification_status
     * (see SpaBranchService::saveLocation) — only submit() does that, once
     * every step is complete.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'formatted_address' => 'required|string',

            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',
        ];
    }
}
