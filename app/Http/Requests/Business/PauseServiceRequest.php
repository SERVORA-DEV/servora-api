<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/appointment-service/{uuid}/pause (AppointmentServiceController::pauseService).
class PauseServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Why it is paused, for the log (the client stepped out, a phone call…). Optional.
        return [
            'reason' => 'nullable|string|max:200',
        ];
    }
}
