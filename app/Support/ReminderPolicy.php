<?php

namespace App\Support;

use App\Models\SpaBranch;
use App\Models\SpaBusinessSetting;

// What a branch tells its clients and when: whether a client is reminded of
// a booking and how many hours before, and whether an unpaid bill is chased.
//
// The business sets these for every branch (Business Settings →
// Notifications → Reminders); a branch may set its own instead (Branch
// Settings → Booking & Policies → Client reminders), kept with its other
// overrides in booking_overrides. A branch that sets none follows the
// business. Reminders go to the client's app feed, so they also need the
// business's in-app channel on.
//
// Pure apart from reading the branch's settings — tested in
// tests/Unit/ReminderPolicyTest.
final class ReminderPolicy
{
    /** The reminder rules a branch can override. */
    public const KEYS = ['send_client_reminder', 'reminder_timing', 'send_payment_reminder'];

    /** The lead times an owner can choose, in hours (also the most the reminder job looks ahead). */
    public const HOURS = [1, 2, 4, 6, 12, 24, 48];

    /**
     * @param  array<string, mixed>  $business  the business's notifications section
     * @param  array<string, mixed>  $overrides  the branch's booking_overrides
     * @return array{send_client_reminder: bool, reminder_timing: int, send_payment_reminder: bool, in_app_channel: bool}
     */
    public static function resolve(array $business, array $overrides): array
    {
        $defaults = SpaBusinessSetting::DEFAULTS['notifications'];
        $pick = fn (string $key) => array_key_exists($key, $overrides) ? $overrides[$key] : ($business[$key] ?? $defaults[$key]);

        $hours = (int) $pick('reminder_timing');
        if (! in_array($hours, self::HOURS, true)) {
            $hours = (int) $defaults['reminder_timing'];
        }

        return [
            'send_client_reminder' => filter_var($pick('send_client_reminder'), FILTER_VALIDATE_BOOLEAN),
            'reminder_timing' => $hours,
            'send_payment_reminder' => filter_var($pick('send_payment_reminder'), FILTER_VALIDATE_BOOLEAN),
            'in_app_channel' => filter_var($business['in_app_channel'] ?? $defaults['in_app_channel'], FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** The rules in force at one branch. */
    public static function for(SpaBranch $branch): array
    {
        $business = $branch->business?->settings?->section('notifications') ?? SpaBusinessSetting::DEFAULTS['notifications'];

        return self::resolve($business, (array) ($branch->booking_overrides ?? []));
    }

    /** Whether the client is to be reminded of a booking at all. */
    public static function remindsClients(array $policy): bool
    {
        return $policy['send_client_reminder'] && $policy['in_app_channel'];
    }

    /** Whether an unpaid bill is to be chased. */
    public static function remindsPayments(array $policy): bool
    {
        return $policy['send_payment_reminder'] && $policy['in_app_channel'];
    }
}
