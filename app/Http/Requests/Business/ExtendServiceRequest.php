<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/appointment-service/{uuid}/extend (AppointmentServiceController::extendService).
class ExtendServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // A longer length of the menu, or extra time in the owner's steps.
        return [
            'service_variant_uuid' => 'required_without:extra_minutes|nullable|uuid',
            'extra_minutes' => 'required_without:service_variant_uuid|nullable|integer|min:1|max:480',
        ];
    }
}
