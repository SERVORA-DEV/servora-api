<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

// Re-auth for a high-value action on an already-authenticated account —
// disabling 2FA or minting fresh recovery codes. Same "prove the current
// password again" pattern as ChangePasswordRequest, deliberately not
// Laravel's session-based password.confirm middleware (that subsystem
// stores a "confirmed at" timestamp in the session, which doesn't fit a
// bearer-token SPA).
class TwoFactorStepUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => [
                'required',
                'string',
            ],
        ];
    }
}
