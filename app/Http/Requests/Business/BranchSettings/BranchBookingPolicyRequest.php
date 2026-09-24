<?php

namespace App\Http\Requests\Business\BranchSettings;

use App\Http\Requests\Business\Settings\BookingDefaultsSettingsRequest;
use Illuminate\Foundation\Http\FormRequest;

class BranchBookingPolicyRequest extends FormRequest
{
    // The rules a branch may set for itself instead of following Business
    // Defaults — mirrors POLICY_RULES on the web (useSettingsHub.ts).
    public const KEYS = [
        'online_booking_enabled', 'walk_in_enabled', 'home_service_enabled', 'allow_reschedule',
        'booking_lead_time_minutes', 'max_advance_booking_days', 'max_services_per_booking',
        'allow_cancellation', 'cancellation_policy_tier', 'cancellation_window_hours',
        'require_deposit', 'deposit_percent',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `overrides` replaces the branch's whole override map; a key left out
     * means "follow the business default". Present but empty is valid.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $shared = BookingDefaultsSettingsRequest::ruleSet();

        $rules = ['overrides' => 'present|array'];
        foreach (self::KEYS as $key) {
            $rules["overrides.{$key}"] = $shared[$key];
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $unknown = array_diff(array_keys((array) $this->input('overrides', [])), self::KEYS);
            if ($unknown) {
                $validator->errors()->add('overrides', 'Not a rule a branch can customize: '.implode(', ', $unknown).'.');
            }
        });
    }
}
