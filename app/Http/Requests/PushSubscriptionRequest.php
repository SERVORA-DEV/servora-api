<?php

namespace App\Http\Requests;

use App\Models\PushSubscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// POST api/push/subscriptions (PushSubscriptionController::store).
class PushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in([PushSubscription::KIND_WEB, PushSubscription::KIND_FCM])],
            'endpoint' => ['required', 'string', 'max:2000'],
            'keys.p256dh' => ['required_if:kind,web', 'nullable', 'string', 'max:200'],
            'keys.auth' => ['required_if:kind,web', 'nullable', 'string', 'max:100'],
            // The device's Notification sound setting, re-sent when it changes.
            'quiet' => ['sometimes', 'boolean'],
        ];
    }
}
