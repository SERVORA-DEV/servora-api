<?php

namespace App\Support;

// One list of genders for every form: owners, admins, staff, clients and
// bookings all offer the same three answers (web: app/utils/gender.ts).
//
// Two columns predate "Prefer not to say" and are too narrow for it:
// staff.gender is enum('Male', 'Female', 'Other') and clients.gender is a
// 10-character string. There the third answer is kept as 'Other', and read
// back as "Prefer not to say" (users.gender already holds the words). An
// old 'Other' sent by a form is still accepted, so nothing saved before breaks.
class Gender
{
    public const CHOICES = ['Male', 'Female', 'Prefer not to say'];

    /** How the third answer is kept in the narrow columns (staff, clients). */
    public const SHORT_FOR_UNSAID = 'Other';

    /** Every value a form may send: the three answers, and the old 'Other'. */
    public static function accepted(): array
    {
        return [...self::CHOICES, self::SHORT_FOR_UNSAID];
    }

    /** One of the three answers, or null for blank or anything else. Case and spaces don't matter. */
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $key = strtolower(trim($value));
        if ($key === strtolower(self::SHORT_FOR_UNSAID)) {
            return 'Prefer not to say';
        }
        foreach (self::CHOICES as $gender) {
            if (strtolower($gender) === $key) {
                return $gender;
            }
        }

        return null;
    }

    /** For staff.gender and clients.gender: the answer, with "Prefer not to say" kept as 'Other'. */
    public static function forShortColumn(mixed $value): mixed
    {
        $clean = self::clean($value);

        if ($clean === null) {
            return $value; // left as sent, so validation can say what's wrong
        }

        return $clean === 'Prefer not to say' ? self::SHORT_FOR_UNSAID : $clean;
    }

    /** For users.gender: the answer as words. */
    public static function forUsers(mixed $value): mixed
    {
        return self::clean($value) ?? $value;
    }
}
