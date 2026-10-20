<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// A commission plan's name and rate. Whether the rate fits the kind Staff
// Policies sets (a percentage is at most 100), and whether the name is free,
// is settled in CommissionPlanService.
class CommissionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'min:1', 'max:40'],
            'rate' => [$isCreate ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:1000000'],
        ];
    }
}
