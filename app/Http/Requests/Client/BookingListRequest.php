<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

// GET api/client/appointments (ClientBookingController::index).
class BookingListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['nullable', 'in:upcoming,past,cancelled,all'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ];
    }
}
