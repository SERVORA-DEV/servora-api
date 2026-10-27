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

    public function test_each_document_has_a_short_summary_and_tagged_sections(): void
    {
        $documents = $this->getJson('/api/legal')->assertOk()->json('data.documents');

        foreach (['terms', 'privacy'] as $key) {
            $this->assertNotEmpty($documents[$key]['summary'], "{$key} has no summary");

            foreach ($documents[$key]['sections'] as $section) {
                $this->assertContains($section['audience'], ['all', 'client', 'owner']);
            }
        }

        $audiences = collect($documents['terms']['sections'])->pluck('audience', 'heading');
        $this->assertSame('owner', $audiences['Spa owners: verification']);
        $this->assertSame('client', $audiences['Clients: bookings and visits']);
    }

    public function test_no_placeholder_reaches_readers_once_the_operator_details_are_set(): void
    {
        config(['legal.operator' => [
            'name' => 'Servora Inc.', 'address' => 'Davao City', 'email' => 'hello@example.com',
            'dpo' => 'A. Dela Cruz', 'venue' => 'Davao City',
        ]]);

        $this->assertSame([], \App\Support\LegalDocuments::unresolved());
        $this->artisan('legal:check')->assertSuccessful();
    }

    public function test_the_check_names_every_placeholder_still_showing(): void
    {
        config(['legal.operator' => [
            'name' => '[Company name]', 'address' => 'Davao City', 'email' => 'hello@example.com',
            'dpo' => 'A. Dela Cruz', 'venue' => 'Davao City',
        ]]);

        $this->assertContains('terms intro: [Company name]', \App\Support\LegalDocuments::unresolved());
        $this->artisan('legal:check')->assertFailed();
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
        Mail::fake();

        $this->postJson('/api/business/administrator/register', $this->credentials('owner@example.com', ['terms_accepted' => true]))
            ->assertCreated();

        // No account until the link in the email is pressed.
        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
        $link = Mail::sent(\App\Mail\RegistrationLinkMail::class)->first()->url;
        $this->get($link)->assertRedirect();

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
