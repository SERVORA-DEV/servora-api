<?php

namespace App\Http\Requests\Business\Settings;

use Illuminate\Foundation\Http\FormRequest;

class NotificationSettingsRequest extends FormRequest
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
        $hours = 'in:1,2,4,6,12,24,48';

        return [
            'on_new_booking' => 'sometimes|boolean',
            'on_cancellation' => 'sometimes|boolean',
            'on_reschedule' => 'sometimes|boolean',
            'on_client_no_show' => 'sometimes|boolean',
            'on_service_end' => 'sometimes|boolean',

            'on_manager_offerings' => 'sometimes|boolean',
            'on_manager_hours' => 'sometimes|boolean',
            'on_manager_booking_rules' => 'sometimes|boolean',
            'on_manager_rooms' => 'sometimes|boolean',
            'on_manager_staff' => 'sometimes|boolean',
            'on_manager_listing' => 'sometimes|boolean',

            'lock_owner_hours' => 'sometimes|boolean',
            'lock_owner_booking_rules' => 'sometimes|boolean',
            'lock_owner_catalog' => 'sometimes|boolean',
            'lock_owner_offerings' => 'sometimes|boolean',
            'lock_owner_staff' => 'sometimes|boolean',
            'lock_owner_rooms' => 'sometimes|boolean',
            'lock_owner_listing' => 'sometimes|boolean',

            'frontdesk_hours' => 'sometimes|boolean',
            'frontdesk_booking_rules' => 'sometimes|boolean',
            'frontdesk_offerings' => 'sometimes|boolean',
            'frontdesk_staff' => 'sometimes|boolean',
            'frontdesk_rooms' => 'sometimes|boolean',
            'frontdesk_listing' => 'sometimes|boolean',

            'send_client_reminder' => 'sometimes|boolean',
            'reminder_timing' => 'sometimes|required|integer|'.$hours,
            'send_staff_reminder' => 'sometimes|boolean',
            'staff_reminder_timing' => 'sometimes|required|integer|'.$hours,
            'send_payment_reminder' => 'sometimes|boolean',
            'send_birthday_greeting' => 'sometimes|boolean',

            'promotional_enabled' => 'sometimes|boolean',
            'promotional_channel' => 'sometimes|required|in:email,sms,both',
            'subscription_renewal_alert' => 'sometimes|boolean',
            'renewal_alert_days' => 'sometimes|required|integer|in:3,7,14,30',
            'system_update_notif' => 'sometimes|boolean',

            'email_channel' => 'sometimes|boolean',
            'sms_channel' => 'sometimes|boolean',
            'push_channel' => 'sometimes|boolean',
            'in_app_channel' => 'sometimes|boolean',
        ];
    }
}
