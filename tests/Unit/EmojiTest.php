<?php

namespace Tests\Unit;

use App\Support\Emoji;
use PHPUnit\Framework\TestCase;

class EmojiTest extends TestCase
{
    public function test_emoji_are_found_in_text(): void
    {
        foreach (['Relax 😀', '💆 Massage', 'Love ❤️', 'Top ⭐', 'Flag 🇵🇭', '1️⃣ first', 'ok ✅', 'Family 👨‍👩‍👧'] as $text) {
            $this->assertTrue(Emoji::in($text), $text);
        }
    }

    public function test_ordinary_text_is_left_alone(): void
    {
        foreach (['Serenity Spa', 'Niño Cañete', 'Café ™ ® ©', '₱1,200.00', '東京', 'Mon–Fri 9:00 – 18:00', "O'Brien & Sons", '50% off — today'] as $text) {
            $this->assertFalse(Emoji::in($text), $text);
        }
    }

    public function test_the_fields_holding_them_are_named_the_promo_banner_is_allowed_and_the_old_password_is_not_looked_at(): void
    {
        $input = [
            'name' => 'Spa 😀',
            'promo_text' => '🎉 Grand opening',
            'password' => 'pa55😀word',
            'branch' => ['notes' => ['fine', 'not fine 💆']],
            'phone' => '0917',
            'current_password' => 'old😀pass',
        ];

        $this->assertSame(['name', 'password', 'branch.notes.1'], Emoji::fieldsIn($input));
    }
}
