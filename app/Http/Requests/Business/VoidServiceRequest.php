<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/appointment-service/{uuid}/void (AppointmentController::voidService).
class VoidServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => 'required|string|min:3|max:255'];
    }
}
