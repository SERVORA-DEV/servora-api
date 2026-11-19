<?php

namespace Tests\Feature;

use App\Mail\PasswordResetOtpMail;
use App\Mail\RegistrationOtpMail;
use App\Models\User;
use App\Support\Access\OneTimeCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// The emailed codes (App\Support\Access\OneTimeCodes, one_time_codes): the sign-up email check and
// the forgotten-password reset share one table, one row per purpose, email and audience. It took
// over from email_verification_otps and password_reset_tokens (2026_01_01_000790).
//
// Needs PostgreSQL (see phpunit.xml):
//   php artisan test --filter=OneTimeCodesTest
class OneTimeCodesTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'mia@example.com';

    private function client(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'client', 'email' => self::EMAIL, 'password' => 'Client-pass-1', 'email_verified_at' => now(),
        ], $attributes));
    }

    private function rows(): array
    {
        return DB::table('one_time_codes')->orderBy('purpose')->get(['purpose', 'email', 'audience', 'attempts'])
            ->map(fn ($r) => (array) $r)->all();
    }

    public function test_a_sign_up_code_verifies_the_email_and_is_used_up(): void
    {
        Mail::fake();
        $client = $this->client(['email_verified_at' => null]);

        $this->postJson('/api/auth/resend-registration-otp', ['email' => self::EMAIL])->assertOk();
        $this->assertSame([['purpose' => 'email_verification', 'email' => self::EMAIL, 'audience' => 'mobile', 'attempts' => 0]], $this->rows());

        $otp = Mail::sent(RegistrationOtpMail::class)->last()->otp;
        $this->postJson('/api/auth/verify-registration-otp', ['email' => self::EMAIL, 'otp' => $otp])->assertOk();

        $this->assertNotNull($client->fresh()->email_verified_at);
        $this->assertSame([], $this->rows());
    }

    public function test_a_password_reset_code_becomes_a_reset_token_and_both_are_used_up(): void
    {
        Mail::fake();
        $client = $this->client();

        $this->postJson('/api/auth/forget-password', ['email' => self::EMAIL, 'audience' => 'mobile'])->assertOk();
        $otp = Mail::sent(PasswordResetOtpMail::class)->last()->otp;

        $token = $this->postJson('/api/auth/forget-password/verify-otp', ['email' => self::EMAIL, 'audience' => 'mobile', 'otp' => $otp])
            ->assertOk()->json('reset_token');
        // The code itself can't be used twice: it was swapped for the token.
        $this->postJson('/api/auth/forget-password/verify-otp', ['email' => self::EMAIL, 'audience' => 'mobile', 'otp' => $otp])->assertStatus(422);

        $this->postJson('/api/auth/reset-password', [
            'email' => self::EMAIL, 'audience' => 'mobile', 'reset_token' => $token,
            'password' => 'New-secret-1', 'password_confirmation' => 'New-secret-1',
        ])->assertOk();

        $this->assertTrue(Hash::check('New-secret-1', $client->fresh()->password));
        $this->assertSame([], $this->rows());
    }

    // The point of the purpose in the key: a sign-up code and a reset code for the same
    // account live side by side, and wrong guesses at one don't count against the other.
    public function test_the_two_purposes_never_touch_each_other(): void
    {
        Mail::fake();
        $client = $this->client();
        OneTimeCodes::put(OneTimeCodes::EMAIL_VERIFICATION, $client, Hash::make('111111'));

        $this->postJson('/api/auth/forget-password', ['email' => self::EMAIL, 'audience' => 'mobile'])->assertOk();
        $this->assertSame(['email_verification', 'password_reset'], array_column($this->rows(), 'purpose'));

        $wrong = Mail::sent(PasswordResetOtpMail::class)->last()->otp === '000000' ? '000001' : '000000';
        foreach (range(1, 2) as $i) {
            $this->postJson('/api/auth/forget-password/verify-otp', ['email' => self::EMAIL, 'audience' => 'mobile', 'otp' => $wrong])->assertStatus(422);
        }

        $this->assertSame(2, OneTimeCodes::find(OneTimeCodes::PASSWORD_RESET, $client)->attempts);
        $this->assertSame(0, OneTimeCodes::find(OneTimeCodes::EMAIL_VERIFICATION, $client)->attempts);
        $this->assertTrue(Hash::check('111111', OneTimeCodes::find(OneTimeCodes::EMAIL_VERIFICATION, $client)->code_hash));
    }

    public function test_an_expired_code_is_refused_and_thrown_away(): void
    {
        Mail::fake();
        $this->client();
        $this->postJson('/api/auth/forget-password', ['email' => self::EMAIL, 'audience' => 'mobile'])->assertOk();
        $otp = Mail::sent(PasswordResetOtpMail::class)->last()->otp;

        $this->travel(11)->minutes();

        $this->postJson('/api/auth/forget-password/verify-otp', ['email' => self::EMAIL, 'audience' => 'mobile', 'otp' => $otp])->assertStatus(410);
        $this->assertSame([], $this->rows());
    }

    // The switch: codes still in the two old tables are copied over, so one in use while the
    // migration runs still works; running it again changes nothing.
    public function test_the_migration_carries_codes_over_from_the_old_tables(): void
    {
        Mail::fake();
        $this->client();
        DB::table('email_verification_otps')->insert(['email' => 'new@example.com', 'audience' => 'mobile', 'otp' => Hash::make('222222'), 'created_at' => now(), 'attempts' => 1]);
        DB::table('password_reset_tokens')->insert(['email' => self::EMAIL, 'audience' => 'mobile', 'token' => Hash::make('333333'), 'created_at' => now(), 'attempts' => 0]);

        $migration = require database_path('migrations/2026_01_01_000790_create_one_time_codes_table.php');
        $migration->up();
        $migration->up();

        $this->assertSame([
            ['purpose' => 'email_verification', 'email' => 'new@example.com', 'audience' => 'mobile', 'attempts' => 1],
            ['purpose' => 'password_reset', 'email' => self::EMAIL, 'audience' => 'mobile', 'attempts' => 0],
        ], $this->rows());

        // The copied reset code works as if it had been sent after the switch.
        $this->postJson('/api/auth/forget-password/verify-otp', ['email' => self::EMAIL, 'audience' => 'mobile', 'otp' => '333333'])
            ->assertOk()->assertJsonStructure(['reset_token']);
        // The old tables are left as they were.
        $this->assertSame(1, DB::table('email_verification_otps')->count());
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
    }
}
