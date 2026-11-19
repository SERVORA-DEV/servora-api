<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

// POST api/auth/verification-status (AuthController::verificationStatus).
class VerificationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status_token' => ['required', 'string', 'max:100']];
    }
}
