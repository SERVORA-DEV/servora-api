<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// Validates POST appointment/{uuid}/services and
// appointment/{uuid}/additional-services — same body shape for both, since
// adding an additional service to an active appointment (rule 11) is the
// same operation as the initial post-check-in service selection.
class AppointmentServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_variant_uuid' => 'required|uuid|exists:service_variants,uuid',
            'quantity' => 'nullable|integer|min:1',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ];
    }
}
