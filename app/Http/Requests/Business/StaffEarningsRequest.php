<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// The period a staff member's earnings are shown for.
// GET api/business/staff/{uuid}/earnings, GET api/business/earnings (StaffController::earnings, StaffEarningsController::index).
class StaffEarningsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
