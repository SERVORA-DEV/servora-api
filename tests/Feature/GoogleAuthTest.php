<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// "Continue with Google" for business owners: POST /api/auth/google with the
// ID token from the browser. Google's tokeninfo answer is faked here — these
// tests cover what the API does with it.
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'test-client-id.apps.googleusercontent.com';
    private const EMAIL = 'owner@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => self::CLIENT_ID]);
    }

    private function fakeGoogle(array $claims = [], int $status = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(array_merge([
                'aud' => self::CLIENT_ID,
                'iss' => 'https://accounts.google.com',
                'email' => self::EMAIL,
                'email_verified' => 'true',
                'exp' => (string) (time() + 3600),
            ], $claims), $status),
        ]);
    }

    // $agree mirrors the sign-up page, which sends the Terms agreement.
    private function signIn(bool $agree = false)
    {
        return $this->postJson('/api/auth/google', ['credential' => 'fake-id-token'] + ($agree ? ['terms_accepted' => true] : []));
    }

    public function test_a_new_email_becomes_a_verified_owner_and_is_signed_in(): void
    {
        $this->fakeGoogle();

        $response = $this->signIn(agree: true)->assertCreated();

        $response->assertJsonStructure(['user', 'token']);

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame('business_owner', $user->role);
        $this->assertSame('web', $user->audience);
        $this->assertSame('Active', $user->account_status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('user_permissions', ['user_id' => $user->id]);
        $this->assertSame(config('legal.version'), $user->terms_version);
        $this->assertNotNull($user->terms_accepted_at);
    }

    public function test_an_unknown_email_is_not_created_from_the_sign_in_page(): void
    {
        $this->fakeGoogle();

        $this->signIn()->assertNotFound()->assertJsonPath('needs_signup', true);

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_an_existing_owner_is_signed_in_without_a_second_account(): void
    {
        $owner = User::factory()->create([
            'role' => 'business_owner',
            'email' => self::EMAIL,
            'email_verified_at' => now(),
            'account_status' => 'Active',
        ]);

        $this->fakeGoogle();

        $this->signIn()->assertOk()->assertJsonPath('user.uuid', $owner->uuid);

        $this->assertSame(1, User::where('email', self::EMAIL)->count());
    }

    public function test_an_unverified_password_is_discarded_when_google_proves_the_owner(): void
    {
        $owner = User::factory()->create([
            'role' => 'business_owner',
            'email' => self::EMAIL,
            'password' => 'Typed-By-Someone-1',
            'email_verified_at' => null,
            'account_status' => 'Pending',
        ]);

        $this->fakeGoogle();

        $this->signIn()->assertOk();

        $owner->refresh();
        $this->assertNotNull($owner->email_verified_at);
        $this->assertSame('Active', $owner->account_status);
        $this->assertFalse(Hash::check('Typed-By-Someone-1', $owner->password));
    }

    public function test_a_token_issued_for_another_app_is_rejected(): void
    {
        $this->fakeGoogle(['aud' => 'someone-else.apps.googleusercontent.com']);

        $this->signIn()->assertUnauthorized();

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_an_email_google_has_not_verified_is_rejected(): void
    {
        $this->fakeGoogle(['email_verified' => 'false']);

        $this->signIn()->assertUnauthorized();
    }

    public function test_a_token_google_does_not_recognise_is_rejected(): void
    {
        $this->fakeGoogle(['error' => 'invalid_token'], 400);

        $this->signIn()->assertUnauthorized();
    }

    public function test_an_administrator_email_cannot_use_google_sign_in(): void
    {
        User::factory()->create([
            'role' => 'system_administrator',
            'email' => self::EMAIL,
            'email_verified_at' => now(),
            'account_status' => 'Active',
        ]);

        $this->fakeGoogle();

        $this->signIn()->assertForbidden();
    }

    public function test_a_deactivated_owner_cannot_sign_in(): void
    {
        User::factory()->create([
            'role' => 'business_owner',
            'email' => self::EMAIL,
            'email_verified_at' => now(),
            'account_status' => 'Suspended',
        ]);

        $this->fakeGoogle();

        $this->signIn()->assertForbidden();
    }

    public function test_an_owner_with_two_factor_gets_a_challenge_not_a_token(): void
    {
        User::factory()->create([
            'role' => 'business_owner',
            'email' => self::EMAIL,
            'email_verified_at' => now(),
            'account_status' => 'Active',
            'two_factor_confirmed_at' => now(),
        ]);

        $this->fakeGoogle();

        $this->signIn()
            ->assertOk()
            ->assertJsonPath('requires_two_factor', true)
            ->assertJsonMissingPath('token');
    }

    public function test_it_is_unavailable_when_no_client_id_is_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->signIn()->assertStatus(503);
    }

    public function test_the_credential_is_required(): void
    {
        $this->postJson('/api/auth/google', [])->assertUnprocessable();
    }
}
