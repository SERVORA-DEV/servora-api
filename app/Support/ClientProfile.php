<?php

namespace App\Support;

// What a spa records about a client beyond their name and contact: their
// gender, and their age. The age is kept as a birth year — "34" typed today
// would be wrong next year, a year of birth is not — and shown as an age.
//
// A home service needs more than a visit to the spa does: someone is going
// to the client's door, so a phone number and the gender are required.
//
// Pure — tested in tests/Unit/ClientDetailsTest; mirrored on the site in
// app/utils/clientProfile.ts (keep the two in step).
class ClientProfile
{
    public const GENDERS = ['Male', 'Female'];

    public const OLDEST = 120;

    /** 'Male' | 'Female', or null for anything else (blank, "Prefer not to say"). */
    public static function gender(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $key = strtolower(trim($value));
        foreach (self::GENDERS as $gender) {
            if (strtolower($gender) === $key) {
                return $gender;
            }
        }

        return null;
    }

    /** The year someone of `$age` was born, counted from `$thisYear`; null when no (sensible) age was given. */
    public static function birthYear(mixed $age, int $thisYear): ?int
    {
        if ($age === null || $age === '' || ! is_numeric($age)) {
            return null;
        }

        $age = (int) $age;

        return $age >= 0 && $age <= self::OLDEST ? $thisYear - $age : null;
    }

    /** Their age in `$thisYear`, or null when no birth year is known. */
    public static function age(?int $birthYear, int $thisYear): ?int
    {
        if ($birthYear === null) {
            return null;
        }

        $age = $thisYear - $birthYear;

        return $age >= 0 && $age <= self::OLDEST ? $age : null;
    }

    /**
     * What is still missing before this client can be booked a home service:
     * any of 'phone number' and 'gender'.
     *
     * @param  array{phone_number?: ?string, gender?: ?string}  $client
     * @return string[]
     */
    public static function missingForHome(array $client): array
    {
        $missing = [];
        if (trim((string) ($client['phone_number'] ?? '')) === '') {
            $missing[] = 'phone number';
        }
        if (self::gender($client['gender'] ?? null) === null) {
            $missing[] = 'gender';
        }

        return $missing;
    }

    /** "Add the client's phone number and gender for a home service." */
    public static function homeMessage(array $missing): string
    {
        return 'Add the client\'s ' . implode(' and ', $missing) . ' for a home service.';
    }
}
