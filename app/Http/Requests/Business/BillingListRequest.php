<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

// GET api/business/billings (BillingController::index).
class BillingListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'display_status' => ['nullable', 'in:all,owed,paid,refunded'],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:120'],
            'branch_uuid' => ['nullable', 'uuid'],
            'sort' => ['nullable', 'in:latest,oldest'],
        ];
    }
}
