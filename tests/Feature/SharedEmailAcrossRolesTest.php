<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// One email may back an owner-side account ('web') and a client account
// ('mobile'), each with its own password; login and password reset pick the
// account by the `audience` the calling app sends.
class SharedEmailAcrossRolesTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'both@example.com';

    private function owner(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'business_owner',
            'email' => self::EMAIL,
            'password' => 'owner-pass-1',
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function client(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'client',
            'email' => self::EMAIL,
            'password' => 'client-pass-1',
            'email_verified_at' => now(),
        ], $attributes));
    }

    public function test_audience_is_derived_from_role(): void
    {
        $this->assertSame('web', $this->owner()->audience);
        $this->assertSame('mobile', $this->client()->audience);
    }

    public function test_an_owner_and_a_client_can_register_with_the_same_email(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/register', [
            'email' => self::EMAIL,
            'password' => 'client-pass-1',
            'password_confirmation' => 'client-pass-1',
        ])->assertCreated();

        $this->postJson('/api/business/administrator/register', [
            'email' => self::EMAIL,
            'password' => 'owner-pass-1',
            'password_confirmation' => 'owner-pass-1',
        ])->assertCreated();

        $this->assertSame(2, User::where('email', self::EMAIL)->count());
    }

    public function test_the_same_email_is_still_rejected_twice_within_an_audience(): void
    {
        $this->owner();
        $this->postJson('/api/business/administrator/register', [
            'email' => self::EMAIL,
            'password' => 'another-pass-1',
            'password_confirmation' => 'another-pass-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->client();
        $this->postJson('/api/auth/register', [
            'email' => self::EMAIL,
            'password' => 'another-pass-1',
            'password_confirmation' => 'another-pass-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_picks_the_account_by_audience(): void
    {
        $this->owner();
        $this->client();

        $this->postJson('/api/auth/login', [
            'email' => self::EMAIL, 'password' => 'owner-pass-1',
        ])->assertOk()->assertJsonPath('user.role', 'business_owner');

        $this->postJson('/api/auth/login', [
            'email' => self::EMAIL, 'password' => 'client-pass-1', 'audience' => 'mobile',
        ])->assertOk()->assertJsonPath('user.role', 'client');

        // Each password only works on its own side.
        $this->postJson('/api/auth/login', [
            'email' => self::EMAIL, 'password' => 'client-pass-1',
        ])->assertUnauthorized();

        $this->postJson('/api/auth/login', [
            'email' => self::EMAIL, 'password' => 'owner-pass-1', 'audience' => 'mobile',
        ])->assertUnauthorized();
    }

    public function test_password_reset_only_touches_the_requested_account(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $client = $this->client();
        $ownerHash = $owner->fresh()->password;

        $this->postJson('/api/auth/forget-password', [
            'email' => self::EMAIL, 'audience' => 'mobile',
        ])->assertOk();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => self::EMAIL, 'audience' => 'mobile']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => self::EMAIL, 'audience' => 'web']);
        $this->assertSame($ownerHash, $owner->fresh()->password);
    }

    public function test_client_otp_verification_does_not_verify_the_owner(): void
    {
        Mail::fake();
        $owner = $this->owner(['email_verified_at' => null]);
        $client = $this->client(['email_verified_at' => null]);

        $this->postJson('/api/auth/resend-registration-otp', ['email' => self::EMAIL])->assertOk();
        $this->assertDatabaseHas('email_verification_otps', ['email' => self::EMAIL, 'audience' => 'mobile']);
        $this->assertDatabaseMissing('email_verification_otps', ['email' => self::EMAIL, 'audience' => 'web']);

        $this->assertNull($owner->fresh()->email_verified_at);
    }
}
