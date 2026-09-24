<?php

namespace App\Http\Requests\Business\Settings;

use Illuminate\Foundation\Http\FormRequest;

class BookingDefaultsSettingsRequest extends FormRequest
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
        return self::ruleSet();
    }

    /**
     * Shared with BranchBookingPolicyRequest, so a branch override is held
     * to exactly the same limits as the business default it replaces.
     *
     * @return array<string, mixed>
     */
    public static function ruleSet(): array
    {
        return [
            'online_booking_enabled' => 'sometimes|boolean',
            'walk_in_enabled' => 'sometimes|boolean',
            'home_service_enabled' => 'sometimes|boolean',
            'allow_therapist_selection' => 'sometimes|boolean',
            'auto_assign_therapist' => 'sometimes|boolean',
            'allow_reschedule' => 'sometimes|boolean',
            'allow_cancellation' => 'sometimes|boolean',

            'booking_lead_time_minutes' => 'sometimes|required|integer|min:0|max:10080',
            'max_advance_booking_days' => 'sometimes|required|integer|min:1|max:365',
            'max_services_per_booking' => 'sometimes|required|integer|min:1|max:20',

            'cancellation_window_hours' => 'sometimes|required|integer|min:0|max:720',
            'cancellation_policy_tier' => 'sometimes|required|in:flexible,moderate,strict',
            'no_show_policy' => 'sometimes|required|in:none,warn,suspend',
            'no_show_suspend_after' => 'sometimes|required|integer|min:1|max:20',

            'require_deposit' => 'sometimes|boolean',
            'deposit_percent' => 'sometimes|required|integer|min:1|max:100',

            'queue_enabled' => 'sometimes|boolean',
            'auto_assign_queue' => 'sometimes|boolean',
            'max_queue_size' => 'sometimes|required|integer|min:1|max:500',
            'queue_number_format' => 'sometimes|required|in:numeric,alpha-numeric',
            'estimated_wait_display' => 'sometimes|boolean',
            'walk_ins_first_priority' => 'sometimes|boolean',

            'allow_service_extension' => 'sometimes|boolean',
            'extension_increment_mins' => 'sometimes|required|integer|min:5|max:120',
            'max_extension_mins' => 'sometimes|required|integer|min:0|max:480',
            'extension_requires_approval' => 'sometimes|boolean',

            'cancellation_tiers' => 'sometimes|array',
            'cancellation_tiers.flexible' => 'sometimes|required|integer|min:0|max:100',
            'cancellation_tiers.moderate' => 'sometimes|required|integer|min:0|max:100',
            'cancellation_tiers.strict' => 'sometimes|required|integer|min:0|max:100',
        ];
    }
}
