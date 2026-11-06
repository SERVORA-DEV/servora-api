<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// A person's name (first, middle, last, suffix): letters, including ñ and
// accented ones, with spaces, hyphens, apostrophes and periods between them —
// "Ma. Cristina", "Dela Cruz-Santos", "O'Brien", "Jr.", "III". No digits or
// other symbols. The web app strips those as they're typed (utils/personName.ts).
class PersonName implements ValidationRule
{
    public static function passes(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match("/^[\p{L}\p{M}][\p{L}\p{M} .'’\-]*$/u", trim($value));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! self::passes($value)) {
            $fail('Use letters only. Spaces, hyphens (-), apostrophes (\') and periods (.) are fine.');
        }
    }
}
