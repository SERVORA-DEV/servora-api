<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// PUT api/business/programs/{uuid}/branches/{branchUuid} (CustomerProgramController::branch).
class ProgramBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['offered' => ['required', 'boolean']];
    }
}
