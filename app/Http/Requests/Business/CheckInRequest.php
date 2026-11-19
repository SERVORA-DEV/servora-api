<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/appointment/{uuid}/checkin (AppointmentController::checkIn).
class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // `now`: a booking for a later day, served today instead — moved to now
        // and checked in at the back of the line.
        // `place`: where an early or late reservation joins the line — now,
        // last (both: from now) or at its booked time; `allow_past_closing`:
        // take a visit that would run past closing anyway. Asked for by the
        // 409 `check_in_choice` answer (CheckInService::checkIn).
        return [
            'now' => ['sometimes', 'boolean'],
            'place' => ['sometimes', 'nullable', 'in:now,booked,last'],
            'allow_past_closing' => ['sometimes', 'boolean'],
        ];
    }
}
