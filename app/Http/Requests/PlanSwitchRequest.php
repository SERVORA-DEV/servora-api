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
            // Moving to a plan smaller than what they use: what to keep
            // (PlanFitService). Ignored when everything fits.
            'extra_branches' => 'sometimes|integer|min:0|max:1000',
            'extra_accounts' => 'sometimes|integer|min:0|max:1000',
            'keep' => 'sometimes|nullable|array',
            'keep.branch_uuids' => 'sometimes|array',
            'keep.branch_uuids.*' => 'uuid',
            'keep.account_uuids' => 'sometimes|array',
            'keep.account_uuids.*' => 'uuid',
        ];
    }
}
