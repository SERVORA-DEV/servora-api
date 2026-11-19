<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// GET api/business/branch-schedule (BranchScheduleController::index).
class BranchScheduleListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // A uuid: anything else used to reach the database and fail there (500).
        return ['spa_branch_uuid' => 'required|uuid'];
    }
}
