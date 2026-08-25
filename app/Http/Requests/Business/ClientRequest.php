<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// Validates POST /business/client (register a new client). Also reused as
// the shape of the nested "client" object inside AppointmentRequest when
// creating an appointment for a walk-in that doesn't exist yet.
// Deliberately minimal — clients only stores simple operational attributes;
// an account holder's fuller profile lives on `users` instead (see
// ClientResource's `account` block), so there's nothing to collect here
// beyond how to identify/contact them.
class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone_number' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string',
        ];
    }
}
