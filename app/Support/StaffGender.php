<?php

namespace App\Support;

// The staff gender a client would rather have: none (anyone), male or female.
// It narrows the list of staff while booking and is kept on the booking, so
// whoever assigns later knows. It is advice — it never refuses an assignment.
//
// A member of staff whose gender isn't on their record matches no preference;
// they only show when anyone will do.
//
// Pure — tested in tests/Unit/StaffGenderTest; mirrored on the site in
// app/utils/staffGender.ts (keep the two in step).
class StaffGender
{
    public const PREFERENCES = ['Male', 'Female'];

    /** 'Male' | 'Female', or null for no preference (and for anything else). */
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $key = strtolower(trim($value));
        foreach (self::PREFERENCES as $preference) {
            if (strtolower($preference) === $key) {
                return $preference;
            }
        }

        return null;
    }

    /** Whether a member of staff of `$staffGender` suits `$preference`. */
    public static function matches(?string $staffGender, ?string $preference): bool
    {
        $preference = self::clean($preference);

        return $preference === null || self::clean($staffGender) === $preference;
    }

    /** "Prefers female staff", or null when anyone will do. */
    public static function label(?string $preference): ?string
    {
        $preference = self::clean($preference);

        return $preference === null ? null : 'Prefers ' . strtolower($preference) . ' staff';
    }
}
