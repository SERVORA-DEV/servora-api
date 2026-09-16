<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Covers PATCH /client/profile — the endpoint the mobile onboarding step
// writes to, since client registration only ever collects an email and a
// password. Driven over HTTP rather than against the service directly because
// half of what is under test is the route's role gate and the FormRequest's
// uniqueness rules.
//
// Needs MySQL. phpunit.xml points at sqlite :memory:, which cannot build this
// schema at all — several migrations use raw `ALTER TABLE ... MODIFY ... ENUM`,
// which is MySQL-only. Run against the existing test database instead:
//
//   DB_CONNECTION=mysql DB_DATABASE=servora_testing DB_URL= \
//     php artisan test --filter=ClientProfileTest
class ClientProfileTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'client',
            'first_name' => null,
            'last_name' => null,
            'phone_number' => null,
            'onboarding_completed_at' => null,
        ], $attributes));
    }

    public function test_it_fills_in_the_profile_and_stamps_completion(): void
    {
        $user = $this->client();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/client/profile', [
            'first_name' => '  Ana  ',
            'last_name' => 'Reyes',
            'phone_number' => '09171234567',
        ]);

        $response->assertOk();
        // A UserResource returned as the response body, so Laravel wraps it —
        // the mobile AppUser.fromResponse unwraps `data` for exactly this.
        $response->assertJsonPath('data.first_name', 'Ana');
        $response->assertJsonPath('data.last_name', 'Reyes');
        $response->assertJsonPath('data.phone_number', '09171234567');

        $user->refresh();
        $this->assertSame('Ana', $user->first_name);
        $this->assertNotNull($user->onboarding_completed_at);
    }

    public function test_auth_me_reflects_the_update(): void
    {
        $user = $this->client();
        Sanctum::actingAs($user);

        $this->patchJson('/api/client/profile', [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'phone_number' => '09171234567',
        ])->assertOk();

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.phone_number', '09171234567');
    }

    public function test_completion_is_stamped_once_and_not_refreshed(): void
    {
        $user = $this->client();
        Sanctum::actingAs($user);

        $this->patchJson('/api/client/profile', [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'phone_number' => '09171234567',
        ])->assertOk();

        $first = $user->fresh()->onboarding_completed_at;

        $this->travel(5)->minutes();

        $this->patchJson('/api/client/profile', [
            'first_name' => 'Anna',
            'last_name' => 'Reyes',
            'phone_number' => '09171234567',
        ])->assertOk();

        $this->assertEquals($first, $user->fresh()->onboarding_completed_at);
    }

    public function test_a_phone_number_already_in_use_is_rejected(): void
    {
        $this->client(['phone_number' => '09171234567']);

        $user = $this->client();
        Sanctum::actingAs($user);

        $this->patchJson('/api/client/profile', [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'phone_number' => '09171234567',
        ])
            ->assertStatus(422)
            // The app renders errors.<field>[0] inline, so this key has to be
            // populated, not just the top-level message.
            ->assertJsonPath('errors.phone_number.0', 'That mobile number is already linked to another Servora account.');
    }

    public function test_a_client_can_keep_their_own_phone_number(): void
    {
        $user = $this->client(['phone_number' => '09171234567']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/client/profile', [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'phone_number' => '09171234567',
        ])->assertOk();
    }

    public function test_the_signup_email_cannot_be_changed(): void
    {
        $user = $this->client(['email' => 'ana@example.com']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/client/profile', [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'phone_number' => '09171234567',
            'email' => 'someone.else@example.com',
        ])->assertOk();

        // It is the login identifier and the key for the OTP and password-reset
        // tables, and it has already been verified — silently moving it would
        // strand email_verified_at on an address nobody proved they own.
        $this->assertSame('ana@example.com', $user->fresh()->email);
    }

    public function test_name_and_phone_are_required(): void
    {
        Sanctum::actingAs($this->client());

        $this->patchJson('/api/client/profile', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name', 'phone_number']);
    }

    public function test_a_business_owner_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'business_owner']));

        $this->patchJson('/api/client/profile', [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'phone_number' => '09171234567',
        ])->assertForbidden();
    }

    public function test_it_requires_authentication(): void
    {
        $this->patchJson('/api/client/profile', [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'phone_number' => '09171234567',
        ])->assertUnauthorized();
    }
}
