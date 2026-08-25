<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class FacilityAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'facility_uuid' => 'required|uuid|exists:facilities,uuid',
        ];
    }
}
