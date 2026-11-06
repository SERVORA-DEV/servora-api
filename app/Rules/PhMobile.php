<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// A Philippine mobile number: 09XX XXX XXXX, +63 9XX XXX XXXX or 63 9XX XXX XXXX.
// Spaces, dashes, dots and brackets are ignored. Every phone field on the site
// sends one of the first two forms (app/components/shared/PhoneField.vue), and
// keeps sending the form it always has, so this only refuses what isn't a PH mobile.
class PhMobile implements ValidationRule
{
    public static function passes(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        $compact = preg_replace('/[\s\-().]/', '', (string) $value);

        return (bool) preg_match('/^(?:\+63|63|0)9\d{9}$/', $compact);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::passes($value)) {
            $fail('Enter a Philippine mobile number: 09XX XXX XXXX.');
        }
    }
}
