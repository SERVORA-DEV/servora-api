<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

// DELETE api/client/account (ClientAccountController::deleteAccount).
class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['password' => ['required', 'string']];
    }
}
