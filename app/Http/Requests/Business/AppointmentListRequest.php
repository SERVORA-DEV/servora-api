<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// GET api/business/appointment (AppointmentController::index).
class AppointmentListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'list_status' => ['nullable', 'in:all,upcoming,in_service,completed,cancelled,no_show'],
            'type' => ['nullable', 'in:all,walkin,booking'],
            'therapist' => ['nullable', 'string', 'max:120'],
            'service' => ['nullable', 'string', 'max:160'],
            'search' => ['nullable', 'string', 'max:120'],
            'branch_uuid' => ['nullable', 'uuid'],
            'view' => ['nullable', 'in:list'],
            'sort' => ['nullable', 'in:date,booked'],
        ];
    }
}
