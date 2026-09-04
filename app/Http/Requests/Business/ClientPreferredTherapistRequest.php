<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// Validates PATCH client/{uuid}/preferred-therapist — a null staff_uuid
// clears the preference. Gated separately from ClientRequest/client_update
// so manager can manage this one field without full client edit rights (see
// client_therapist_manage in config/permission.php).
class ClientPreferredTherapistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'staff_uuid' => 'nullable|uuid|exists:staff,uuid',
        ];
    }
}
