<?php

namespace App\Support;

// A person's name is kept with a capital first letter on every word, however it
// was typed: "juan dela cruz" -> "Juan Dela Cruz", "o'brien" -> "O'Brien",
// "ma. cristina" -> "Ma. Cristina", "iii" -> "III". Only first letters are
// raised; the rest stays as typed, so "McDonald" and "DE LEON" are left alone.
// Mirrors capitalizeName in the web app (utils/personName.ts). Applied to every
// person-name field by App\Http\Middleware\CapitalizeNames.
class NameCase
{
    /** The fields holding a person's name; a dotted path matches by its end (client.first_name). */
    private const FIELDS = [
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'emergency_contact_name',
        'registered_owner_name',
        'billing_address.full_name',
    ];

    public static function capitalize(string $value): string
    {
        $v = preg_replace('/ {2,}/', ' ', trim($value));
        if ($v === null || $v === '') {
            return $value;
        }
        if (preg_match('/^(?:i{1,3}|iv)\.?$/i', $v)) {
            return mb_strtoupper($v);
        }

        return preg_replace_callback(
            '/(^|[ .\'’-])(\p{Ll})/u',
            fn (array $m) => $m[1] . mb_strtoupper($m[2]),
            $v
        ) ?? $v;
    }

    /** The input with every person-name field capitalized (nested ones too). */
    public static function applyTo(array $input, string $path = ''): array
    {
        foreach ($input as $key => $value) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;
            if (is_array($value)) {
                $input[$key] = self::applyTo($value, $here);
            } elseif (is_string($value) && self::isNameField($here)) {
                $input[$key] = self::capitalize($value);
            }
        }

        return $input;
    }

    private static function isNameField(string $path): bool
    {
        foreach (self::FIELDS as $field) {
            if ($path === $field || str_ends_with($path, '.' . $field)) {
                return true;
            }
        }

        return false;
    }
}
