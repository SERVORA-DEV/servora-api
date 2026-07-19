<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubscriptionPlanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => 'required|in:Basic,Premium,Enterprise',

            'name' => 'required|string|max:100',
            'description' => 'nullable|string',

            'billing_cycle' => 'required|in:Monthly,Yearly,Both',

            'monthly_price' => [
                'nullable',
                'numeric',
                'min:0',
                'required_if:billing_cycle,Monthly,Both',
            ],

            'yearly_price' => [
                'nullable',
                'numeric',
                'min:0',
                'required_if:billing_cycle,Yearly,Both',
            ],

            'max_branches' => 'required|integer|min:1',
            'max_user_accounts' => 'required|integer|min:1',

            'package_access' => 'sometimes|boolean',

            'reward_access' => 'sometimes|boolean',
            'review_access' => 'sometimes|boolean',

            'report_access' => 'sometimes|boolean',
            'report_export' => 'sometimes|boolean',

            'mobile_app_access' => 'sometimes|boolean',

            'is_active' => 'sometimes|boolean',
        ];
    }
}
