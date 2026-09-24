<?php

namespace App\Http\Requests\Business\Settings;

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

            'staff_can_view_all_bookings' => 'sometimes|boolean',
            'staff_can_cancel_bookings' => 'sometimes|boolean',
            'staff_can_edit_client_info' => 'sometimes|boolean',
            'staff_can_process_refunds' => 'sometimes|boolean',
            'staff_can_view_reports' => 'sometimes|boolean',
        ];
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
