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
            'segment' => ['nullable', 'in:all,new,regular,upcoming,lapsed,members,app'],
            'branch_uuid' => ['nullable', 'uuid'],
            'sort' => ['nullable', 'in:recent,visits,spent,newest,points,name'],
        ];
    }
}
