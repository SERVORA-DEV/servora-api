<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

// GET api/system/businesses (BusinessController::index).
class BusinessListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'plan' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:all,active,pending,suspended,rejected,unverified'],
            'sort' => ['nullable', 'in:newest,oldest,name'],
        ];
    }
}
