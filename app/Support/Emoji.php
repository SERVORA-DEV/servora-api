<?php

namespace App\Support;

// Text kept by the system is plain text: no emoji, in any name, note, address
// or message. The one place they are welcome is a branch's promo banner
// (promo_text), which is shown to clients as a headline.
//
// Looked for by range rather than by the "Extended Pictographic" property:
// that one also covers the marks ©, ® and ™, which a business name may carry.
class Emoji
{
    private const PATTERN = '/[\x{1F000}-\x{1FAFF}\x{1F1E6}-\x{1F1FF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u';

    // Fields that may hold them, and ones that are never read as text (a password
    // is whatever its owner types).
    public const ALLOWED = ['promo_text'];
    private const SKIPPED = ['password', 'password_confirmation', 'current_password', 'new_password', 'new_password_confirmation'];

    public static function in(string $value): bool
    {
        return $value !== '' && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * The fields of a request's input that hold an emoji, as dotted names.
     *
     * @return list<string>
     */
    public static function fieldsIn(array $input, string $prefix = ''): array
    {
        $found = [];
        foreach ($input as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (in_array((string) $key, self::ALLOWED, true) || in_array((string) $key, self::SKIPPED, true)) {
                continue;
            }
            if (is_array($value)) {
                $found = array_merge($found, self::fieldsIn($value, $name));
            } elseif (is_string($value) && self::in($value)) {
                $found[] = $name;
            }
        }

        return $found;
    }
}
