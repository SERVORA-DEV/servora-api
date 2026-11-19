<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/appointment/{uuid}/queue/skip (AppointmentController::skipQueue).
class SkipQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // behind: the booking (uuid) to move this client behind, or "back" for the end of the line.
        // Left out, they move down one place, as before.
        return ['behind' => ['sometimes', 'nullable', 'string', 'max:64']];
    }
}
