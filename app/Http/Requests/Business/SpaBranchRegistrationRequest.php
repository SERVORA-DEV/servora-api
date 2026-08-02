<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class SpaBranchRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the pin's coordinates are actually saved — the frontend still
     * reverse-geocodes the dropped pin for its own "Is this correct?"
     * confirmation UX, but that resolved string is never sent here or
     * persisted. Review compares the owner's typed address (address/city/
     * province/postal_code) against the map itself, not against a second
     * machine-generated address string.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ];
    }
}
