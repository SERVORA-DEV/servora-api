<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/owner/identity-change/email/confirm (OwnerIdentityChangeController::confirmEmail).
class IdentityChangeEmailCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => 'required|digits:6'];
    }
}
