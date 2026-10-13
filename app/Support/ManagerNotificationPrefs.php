<?php

namespace App\Support;

use App\Models\SpaBusinessSetting;
use App\Models\User;

// A manager's own notification switches (Branch Settings > Notifications):
// which of the owner's changes at their branch they want to hear about.
// Stored per account in users.notification_prefs; every switch is on until
// the manager turns it off, so a missing value (or a database that hasn't
// had the column added yet) reads as "tell me".
//
// The owner sets the floor: a kind ticked under Business Settings >
// Notifications > "Managers are always told about" (lock_owner_… in the
// business's notification settings) is on for every manager and can't be
// switched off here.
class ManagerNotificationPrefs
{
    // A change to the shared catalog that reaches the branch: a standard
    // price, a service switched off or removed.
    public const CATALOG = 'on_owner_catalog';

    public const DEFAULTS = [
        'on_owner_offerings' => true,
        'on_owner_hours' => true,
        'on_owner_booking_rules' => true,
        'on_owner_rooms' => true,
        'on_owner_staff' => true,
        'on_owner_listing' => true,
        self::CATALOG => true,
    ];

    /**
     * The manager's switches as they take effect: their own choices, with
     * anything in $locked forced on.
     *
     * @param  string[]  $locked  Kinds the owner has locked on (see lockedFor()).
     * @return array<string, bool>
     */
    public static function for(User $user, array $locked = []): array
    {
        $stored = is_array($user->notification_prefs) ? $user->notification_prefs : [];
        $prefs = self::DEFAULTS;
        foreach ($prefs as $key => $default) {
            if (array_key_exists($key, $stored)) {
                $prefs[$key] = (bool) $stored[$key];
            }
            if (in_array($key, $locked, true)) {
                $prefs[$key] = true;
            }
        }

        return $prefs;
    }

    public static function wants(User $user, string $key): bool
    {
        return self::for($user)[$key] ?? true;
    }

    /**
     * The kinds the owner of this business has locked on for its managers,
     * as the managers' own keys: lock_owner_hours → on_owner_hours.
     *
     * @return string[]
     */
    public static function lockedFor(?int $businessId): array
    {
        $settings = ($businessId ? AppCache::businessSettings($businessId)?->section('notifications') : null)
            ?? SpaBusinessSetting::DEFAULTS['notifications'];

        $locked = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            if ($settings[str_replace('on_owner_', 'lock_owner_', $key)] ?? false) {
                $locked[] = $key;
            }
        }

        return $locked;
    }
}
