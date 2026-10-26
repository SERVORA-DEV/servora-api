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
            'home_service_enabled' => 'sometimes|boolean',
            'home_service_fee' => 'sometimes|required|integer|min:0|max:100000',
            'home_service_radius_km' => 'sometimes|required|integer|min:1|max:200',
            'home_service_min_minutes' => 'sometimes|required|integer|min:0|max:1440',
            'allow_reschedule' => 'sometimes|boolean',
            'max_reschedules_per_booking' => 'sometimes|required|integer|min:0|max:10',
            'allow_cancellation' => 'sometimes|boolean',

            'max_advance_booking_days' => 'sometimes|required|integer|min:1|max:365',
            'max_services_per_booking' => 'sometimes|required|integer|min:1|max:20',
            'max_bookings_per_client_per_day' => 'sometimes|required|integer|min:1|max:10',
            'max_bookings_per_hour' => 'sometimes|required|integer|min:0|max:50',
            'booking_lead_time_hours' => 'sometimes|required|integer|min:0|max:168',
            'max_service_minutes_per_booking' => 'sometimes|required|integer|min:0|max:1440',
            'allow_booking_overlap' => 'sometimes|boolean',

            'cancellation_window_hours' => 'sometimes|required|integer|min:0|max:720',
            'no_show_policy' => 'sometimes|required|in:none,warn,suspend',
            'no_show_suspend_after' => 'sometimes|required|integer|min:1|max:20',
            'reservation_grace_minutes' => 'sometimes|required|integer|min:0|max:120',


            'queue_enabled' => 'sometimes|boolean',
            'auto_assign_queue' => 'sometimes|boolean',
            'max_queue_size' => 'sometimes|required|integer|min:1|max:500',
            'queue_number_format' => 'sometimes|required|in:numeric,alpha-numeric',
            'walk_ins_first_priority' => 'sometimes|boolean',

            'allow_service_extension' => 'sometimes|boolean',
            'extension_increment_mins' => 'sometimes|required|integer|min:5|max:120',
            'max_extension_mins' => 'sometimes|required|integer|min:0|max:480',
            'extension_requires_approval' => 'sometimes|boolean',

        ];
    }
}
