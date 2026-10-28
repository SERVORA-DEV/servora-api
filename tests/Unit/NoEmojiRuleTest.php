<?php

namespace Tests\Unit;

use App\Rules\NoEmoji;
use App\Support\Emoji;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

// The no-emoji rule for credentials and personal details. The same fixtures
// are checked in the web (tests/emoji.test.ts) and mobile (test/plain_text_test.dart)
// validators, so the three agree on what counts.
class NoEmojiRuleTest extends TestCase
{
    private function passes(string $value, string $field = 'name'): bool
    {
        return Validator::make([$field => $value], [$field => [new NoEmoji()]])->passes();
    }

    public function test_emoji_and_pictographs_are_rejected(): void
    {
        $rejected = [
            "pa55\u{1F600}word",            // 😀
            "\u{1F4AA}",                    // 💪 alone
            "Love \u{2764}\u{FE0F}",        // ❤️ with the emoji variation selector
            "\u{2764}",                     // ❤ text-style, still a pictograph
            "\u{2B50}",                     // ⭐
            "\u{2705}ok",                   // ✅
            "\u{231A}",                     // ⌚
            "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", // 👨‍👩‍👧 ZWJ sequence
            "\u{1F1F5}\u{1F1ED}",           // 🇵🇭 regional-indicator flag
            "1\u{FE0F}\u{20E3}",            // 1️⃣ keycap
            "\u{1F3F4}\u{E0067}\u{E0062}\u{E007F}", // subdivision flag (tag characters)
            "\u{1F44D}\u{1F3FD}",           // 👍🏽 skin tone
            "\u{203C}",                     // ‼
            "\u{2194}",                     // ↔
            "\u{25B6}",                     // ▶
            "\u{3030}",                     // 〰
            "name\u{FE0F}",                 // a variation selector on its own
        ];

        foreach ($rejected as $value) {
            $this->assertFalse($this->passes($value), bin2hex($value));
            $this->assertTrue(Emoji::in($value), bin2hex($value));
        }
    }

    public function test_letters_of_every_language_and_ordinary_symbols_are_kept(): void
    {
        $accepted = [
            'Peña', 'José', 'Zoë', 'Niño Cañete', 'Müller', 'Łukasz', 'Åsa', "O'Brien", 'Anne-Marie',
            '李小龍', '東京', 'Иван', 'محمد', 'עברית', 'हिन्दी', 'ไทย', '한국어', 'Ελένη',
            "\u{0D15}\u{0D4D}\u{200D}\u{0D37}", // Malayalam with a zero-width joiner: a letter shape, not an emoji
            "\u{0643}\u{200C}\u{0627}",          // Arabic with a zero-width non-joiner
            'Pass!@#$%^&*()_+-=[]{};:,.<>/?~`|word1', 'Abcdef123456', "pa\ss'w\"ord",
            '+63 917 123 4567', '(02) 8123-4567', 'a.b+c@example.com', 'EMP-0042',
            'Unit 5, 12 Rizal St. #3', '₱1,200.00', 'Café ™ ® ©', '50% off', '3 × 4 ÷ 2 ± 1 ≈ 2 ≠ 0',
            '← → ↑ ↓', '• – — … “ ”', '',
        ];

        foreach ($accepted as $value) {
            $this->assertTrue($this->passes($value), $value);
            $this->assertFalse(Emoji::in($value), $value);
        }
    }

    public function test_passwords_are_told_in_one_voice_and_other_fields_by_name(): void
    {
        $password = Validator::make(['password' => "pa55\u{1F600}"], ['password' => [new NoEmoji()]]);
        $this->assertSame("Passwords can't contain emoji.", $password->errors()->first('password'));

        $confirmation = Validator::make(['password_confirmation' => "\u{1F600}"], ['password_confirmation' => [new NoEmoji()]]);
        $this->assertSame("Passwords can't contain emoji.", $confirmation->errors()->first('password_confirmation'));

        $name = Validator::make(['first_name' => "Ana \u{1F338}"], ['first_name' => [new NoEmoji()]]);
        $this->assertSame("The first name can't contain emoji.", $name->errors()->first('first_name'));

        $nested = Validator::make(['client' => ['phone_number' => "0917\u{1F4DE}"]], ['client.phone_number' => [new NoEmoji()]]);
        $this->assertSame("The phone number can't contain emoji.", $nested->errors()->first('client.phone_number'));
    }

    public function test_the_sign_in_refuses_an_emoji_in_the_email_or_password(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => "Secret123\u{1F600}"])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', "Passwords can't contain emoji.");

        $this->postJson('/api/auth/login', ['email' => "a\u{1F600}@example.com", 'password' => 'Secret123'])
            ->assertStatus(422);
    }
}
