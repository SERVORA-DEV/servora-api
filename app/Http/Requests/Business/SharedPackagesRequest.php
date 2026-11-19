<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// GET api/business/frontoffice/shared-packages (FrontOfficeLookupController::sharedPackages).
class SharedPackagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date_format:Y-m-d',
            'time' => 'nullable|date_format:H:i',
            'spa_branch_uuid' => 'nullable|uuid',
        ];
    }
}
