<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

// An administrator rejecting a requested change to an owner's details, with the reason they are told.
// POST api/system/registration-changes/{uuid}/reject, POST api/system/identity-changes/{uuid}/reject (BusinessRegistrationChangeController::reject, OwnerIdentityChangeController::reject).
class RejectChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => 'required|string|min:5|max:500'];
    }
}
