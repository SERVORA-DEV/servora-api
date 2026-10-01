<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Owner switching their live subscription to another plan — quote and the
// change itself (see PlanSwitchService).
class PlanSwitchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subscription_plan_uuid' => 'required|uuid|exists:subscription_plans,uuid',
            'billing_cycle' => 'required|in:Monthly,Yearly',
        ];
    }
}
