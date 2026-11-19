<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// GET api/business/frontoffice/booking-board (FrontOfficeLookupController::bookingBoard).
class BookingBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date_format:Y-m-d',
            'duration' => 'nullable|integer|min:15|max:720',
            // The spacing of start times: the New Appointment page asks for 5.
            'step' => 'nullable|integer|in:5,10,15,30,60',
            // Rescheduling: the booking being moved doesn't count against itself.
            'exclude_appointment_uuid' => 'nullable|uuid',
            // A home service visit: only the staff available for home service can take it.
            'home_service' => 'nullable|boolean',
        ];
    }
}
