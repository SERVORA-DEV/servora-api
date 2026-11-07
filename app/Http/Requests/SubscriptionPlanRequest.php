<?php

namespace App\Http\Requests;

use App\Rules\NoEmoji;
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
     * Every paid plan is sold both monthly and yearly (the business picks at checkout), so both
     * prices are required. Only the Free Trial keeps its own setting, as it is never charged.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('category') !== 'Trial') {
            $this->merge(['billing_cycle' => 'Both']);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => 'required|in:Basic,Premium,Enterprise,Trial',

            'name' => ['required', 'string', 'max:100', new NoEmoji()],
            'description' => 'nullable|string',

            'billing_cycle' => 'required|in:Monthly,Yearly,Both',

            'monthly_price' => [
                'nullable',
                'numeric',
                'min:0',
                'max:1000000',
                'decimal:0,2',
                'required_if:billing_cycle,Monthly,Both',
            ],

            'yearly_price' => [
                'nullable',
                'numeric',
                'min:0',
                'max:1000000',
                'decimal:0,2',
                'required_if:billing_cycle,Yearly,Both',
            ],

            'max_branches' => 'required|integer|min:1|max:1000',
            'max_user_accounts' => 'required|integer|min:1|max:1000',

            'package_access' => 'sometimes|boolean',

            'reward_access' => 'sometimes|boolean',
            // Reviews come with every plan, and app bookings have no monthly limit: neither is a
            // plan setting any more. An older screen that still sends them is ignored.

            'report_access' => 'sometimes|boolean',
            'report_export' => 'sometimes|boolean',

            'mobile_app_access' => 'sometimes|boolean',

            'export_mode' => 'nullable|in:none,csv,full',
            'export_days' => 'nullable|integer|min:1|max:36500',
            'extra_branch_price' => 'nullable|numeric|min:0.01|max:1000000',
            'extra_account_price' => 'nullable|numeric|min:0.01|max:1000000',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
