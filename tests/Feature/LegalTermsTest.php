<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// The Terms of Service + Privacy Policy (config/legal.php): served publicly
// by GET /api/legal, and agreed to at sign-up — the account records when and
// which version.
class LegalTermsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Sign-Up-Pass-1';

    private function credentials(string $email, array $extra = []): array
    {
        return array_merge([
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ], $extra);
    }

    public function test_the_documents_are_public_and_carry_the_operator_details(): void
    {
        config(['legal.operator' => ['name' => 'Servora Inc.', 'address' => 'Davao City', 'email' => 'hello@example.com']]);

        $response = $this->getJson('/api/legal')->assertOk();

        $response
            ->assertJsonPath('data.version', config('legal.version'))
            ->assertJsonPath('data.documents.terms.title', 'Terms of Service')
            ->assertJsonPath('data.documents.privacy.title', 'Privacy Policy');

        // Placeholders are filled in, not shown to readers.
        $text = json_encode($response->json('data.documents'));
        $this->assertStringContainsString('Servora Inc.', $text);
        $this->assertStringNotContainsString('{operator}', $text);
        $this->assertStringNotContainsString('{email}', $text);
    }

    public function test_an_owner_cannot_register_without_agreeing(): void
    {
        Notification::fake();

        $this->postJson('/api/business/administrator/register', $this->credentials('owner@example.com'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('terms_accepted');

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }

    public function test_an_owner_who_agrees_has_the_version_and_time_recorded(): void
    {
        Notification::fake();

        $this->postJson('/api/business/administrator/register', $this->credentials('owner@example.com', ['terms_accepted' => true]))
            ->assertCreated();

        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame(config('legal.version'), $owner->terms_version);
        $this->assertNotNull($owner->terms_accepted_at);
    }

    public function test_a_client_cannot_register_without_agreeing(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/register', $this->credentials('client@example.com'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('terms_accepted');
    }

    public function test_a_client_who_agrees_has_the_version_and_time_recorded(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/register', $this->credentials('client@example.com', ['terms_accepted' => true]))
            ->assertCreated();

        $client = User::where('email', 'client@example.com')->firstOrFail();
        $this->assertSame('client', $client->role);
        $this->assertSame(config('legal.version'), $client->terms_version);
        $this->assertNotNull($client->terms_accepted_at);
    }
}
