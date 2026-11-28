<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// GET api/business/client (ClientController::index).
class ClientListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'first_name' => ['required_with:last_name', 'nullable', 'string', 'max:100'],
            'last_name' => ['required_with:first_name', 'nullable', 'string', 'max:100'],
            'segment' => ['nullable', 'in:all,new,regular,upcoming,lapsed,members,app,spa'],
            'branch_uuid' => ['nullable', 'uuid'],
            'sort' => ['nullable', 'in:recent,visits,spent,newest,points,name'],
        ];
    }
}
