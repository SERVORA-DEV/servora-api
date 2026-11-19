<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// POST api/business/appointment/{uuid}/auto-assign (AppointmentServiceController::autoAssign).
class AutoAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['mode' => 'nullable|in:missing,replace_unavailable'];
    }
}
