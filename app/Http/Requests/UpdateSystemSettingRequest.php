<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'sometimes' on all of these — a partial PATCH only needs to
            // send the fields it changes.
            'subscription_grace_period_days' => 'sometimes|required|integer|min:0|max:90',
            'almost_due_notify_enabled' => 'sometimes|boolean',
            'almost_due_notify_days_before' => 'sometimes|required|integer|min:1|max:30',
            'almost_due_repeat_enabled' => 'sometimes|boolean',
            'almost_due_repeat_every_days' => 'sometimes|required|integer|min:1|max:14',
        ];
    }
}
