<?php

namespace App\Http\Requests\Business\Settings;

use App\Models\SpaBusinessSetting;
use Illuminate\Foundation\Http\FormRequest;

class StaffPolicySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isPercentage = $this->input('commission_type', 'percentage') === 'percentage';

        return [
            'commission_enabled' => 'sometimes|boolean',
            'commission_type' => 'sometimes|required|in:percentage,fixed',
            'default_commission_rate' => 'sometimes|required|numeric|min:0|max:'.($isPercentage ? 100 : 1000000),
            'commission_applies_to' => 'sometimes|required|in:service,package,both',
            'commission_release_cycle' => 'sometimes|required|in:daily,weekly,monthly',
            'commission_includes_extension' => 'sometimes|boolean',
            'commission_includes_tips' => 'sometimes|boolean',

            'attendance_tracking' => 'sometimes|boolean',
            'require_check_in' => 'sometimes|boolean',
            'allow_self_check_in' => 'sometimes|boolean',
            'late_threshold_minutes' => 'sometimes|required|integer|min:1|max:240',
            'allow_shift_swap' => 'sometimes|boolean',
            'require_manager_approval' => 'sometimes|boolean',
            'allow_overtime' => 'sometimes|boolean',

            // Default permissions per account role — keys are checked
            // against each role's bundle in withValidator().
            'role_permissions' => 'sometimes|array',
            'role_permissions.manager' => 'sometimes|array',
            'role_permissions.front_officer' => 'sometimes|array',
            'role_permissions.*.*' => 'boolean',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $roles = (array) $this->input('role_permissions', []);

            foreach ($roles as $role => $flags) {
                if (! in_array($role, SpaBusinessSetting::ACCOUNT_ROLES, true)) {
                    $validator->errors()->add('role_permissions', "Unknown role: {$role}.");
                    continue;
                }

                $unknown = array_diff(array_keys((array) $flags), config('permission.'.$role, []));
                if ($unknown) {
                    $validator->errors()->add("role_permissions.{$role}", 'Not a permission this role can have: '.implode(', ', $unknown).'.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_commission_rate.max' => 'A percentage commission can be at most 100%.',
        ];
    }
}
