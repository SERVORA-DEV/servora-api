<?php

namespace App\Support;

// One list of genders for every form: owners, admins, staff, clients and
// bookings all offer the same two answers (web: app/utils/gender.ts).
//
// "Prefer not to say" is no longer offered or accepted, but records saved with it
// are still read as it: users.gender holds the words, and staff.gender (an enum)
// and clients.gender (10 characters) keep it as 'Other'. A form must now send
// Male or Female, so someone with the old answer picks one the next time they save.
class Gender
{
    public const CHOICES = ['Male', 'Female'];

    /** The answer no longer offered, as it reads on records saved before. */
    public const UNSAID = 'Prefer not to say';

    /** How that answer was kept in the narrow columns (staff, clients). */
    public const SHORT_FOR_UNSAID = 'Other';

    /** Every value a form may send. */
    public static function accepted(): array
    {
        return self::CHOICES;
    }

    /** An answer on file (the old "Prefer not to say" included), or null for blank or anything else. Case and spaces don't matter. */
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $key = strtolower(trim($value));
        if ($key === strtolower(self::SHORT_FOR_UNSAID) || $key === strtolower(self::UNSAID)) {
            return self::UNSAID;
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
