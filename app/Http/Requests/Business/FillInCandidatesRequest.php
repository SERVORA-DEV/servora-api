<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// GET api/business/frontoffice/attendance/fill-in-candidates (FrontOfficeAttendanceController::fillInCandidates).
class FillInCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['covering_for' => ['nullable', 'uuid']];
    }
}
