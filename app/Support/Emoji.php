<?php

namespace App\Support;

// Text kept by the system is plain text: no emoji, in any name, note, address
// or message. The one place they are welcome is a branch's promo banner
// (promo_text), which is shown to clients as a headline.
//
// Looked for by range rather than by the "Extended Pictographic" property:
// that one also covers the marks ©, ® and ™, which a business name may carry.
// The ranges: the emoji blocks (1F000–1FAFF, which holds the flags' regional
// indicators too), the symbol blocks that hold ⌚ ☀ ✅ ⭐, and the few single
// pictographs left outside them (‼ ⁉ ℹ ↔ ↩ Ⓜ ▶ ◀ ◻ ⤴ 〰 〽 ㊗ ㊙), plus the
// pieces an emoji sequence is glued from: the emoji variation selector, the
// keycap mark and the tag characters of a subdivision flag. The zero-width
// joiner is not listed: a sequence it joins always holds an emoji, and on its
// own it is a real letter-shaping character in several scripts.
//
// The web and mobile apps carry the same list (app/utils/emoji.ts,
// lib/utils/plain_text.dart); keep the three in step.
class Emoji
{
    private const PATTERN = '/[\x{1F000}-\x{1FAFF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{203C}\x{2049}\x{2139}\x{2194}-\x{2199}\x{21A9}\x{21AA}\x{24C2}\x{25AA}\x{25AB}\x{25B6}\x{25C0}\x{25FB}-\x{25FE}\x{2934}\x{2935}\x{3030}\x{303D}\x{3297}\x{3299}\x{FE0F}\x{20E3}\x{E0020}-\x{E007F}]/u';

    // Fields that may hold them, and the one that is not looked at here: the
    // password a person types to prove it is theirs when changing it, which may
    // predate this rule. The new password, its confirmation and a login's
    // password are looked at (see PASSWORDS).
    public const ALLOWED = ['promo_text'];
    private const SKIPPED = ['current_password'];

    // Said as "Passwords can't contain emoji." rather than naming the field.
    public const PASSWORDS = ['password', 'password_confirmation', 'new_password', 'new_password_confirmation', 'confirm_password'];

    public static function in(string $value): bool
    {
        return $value !== '' && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * What a person is told when this field holds one.
     */
    public static function message(string $field): string
    {
        $parts = array_values(array_filter(explode('.', $field), fn ($part) => ! ctype_digit($part)));
        $name = strtolower((string) end($parts));

        if (in_array($name, self::PASSWORDS, true)) {
            return "Passwords can't contain emoji.";
        }

        $label = str_replace('_', ' ', $name);

        return "The {$label} can't contain emoji.";
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
