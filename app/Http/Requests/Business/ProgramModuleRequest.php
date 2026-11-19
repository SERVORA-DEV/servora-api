<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// PATCH api/business/programs/module (CustomerProgramController::module).
class ProgramModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean']];
    }
}
