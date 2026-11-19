<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// DELETE api/push/subscriptions (PushSubscriptionController::destroy).
class PushUnsubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['endpoint' => ['required', 'string', 'max:2000']];
    }
}
