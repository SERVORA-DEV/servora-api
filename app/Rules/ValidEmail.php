<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// An email someone types in: name@domain.ending — one "@", no spaces, no "..",
// and an ending of at least 2 letters (.com, .ph, .com.ph, .edu.ph…). Laravel's
// own "email" rule lets "juan@gmail" through; this doesn't. No check that the
// domain receives mail (that needs the internet). Mirrors the web app's
// utils/email.ts — keep the two in step.
class ValidEmail implements ValidationRule
{
    private const PATTERN = "/^[A-Za-z0-9](?:[A-Za-z0-9._%+'-]*[A-Za-z0-9_%+'-])?@(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+[A-Za-z]{2,24}$/";

    public static function passes(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $email = trim($value);

        return strlen($email) <= 254 && ! str_contains($email, '..') && (bool) preg_match(self::PATTERN, $email);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! self::passes($value)) {
            $fail('Enter a valid email, like juan@gmail.com.');
        }
    }
}
