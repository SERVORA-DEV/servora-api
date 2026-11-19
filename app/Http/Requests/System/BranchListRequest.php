<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

// GET api/system/branches (BranchController::index).
class BranchListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'business' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'in:all,active,disabled'],
            'operating' => ['nullable', 'in:all,Active,Inactive,Temporarily Closed,unknown'],
        ];
    }
}
