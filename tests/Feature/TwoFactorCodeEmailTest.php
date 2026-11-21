<?php

namespace Tests\Feature;

use App\Mail\TwoFactorSetupOtpMail;
use App\Models\SpaBusiness;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Two-factor codes go to the verified personal email when there is one, and
// otherwise to the email the account signs in with — so every account can
// turn two-factor on without adding a second address first.
//
// Needs PostgreSQL (see phpunit.xml): the local throwaway database only.
class TwoFactorCodeEmailTest extends TestCase
{
    use RefreshDatabase;

    private function owner(array $attributes = []): User
    {
        $owner = User::factory()->create(array_merge([
            'role' => 'business_owner',
            'email' => 'owner@lotusleaf.ph',
            'email_verified_at' => now(),
            'account_status' => 'Active',
        ], $attributes));
        SpaBusiness::factory()->create(['owner_id' => $owner->id]);

        return $owner;
    }

    public function test_without_a_personal_email_the_sign_in_email_is_used(): void
    {
        Mail::fake();
        $owner = $this->owner();
        Sanctum::actingAs($owner);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.code_email', 'owner@lotusleaf.ph')
            ->assertJsonPath('data.code_email_is_sign_in', true);

        $this->postJson('/api/business/account/two-factor/enable')
            ->assertOk()
            ->assertJsonPath('sent_to', 'owner@lotusleaf.ph');
        Mail::assertSent(TwoFactorSetupOtpMail::class, fn ($mail) => $mail->hasTo('owner@lotusleaf.ph'));
    }

    public function test_a_verified_personal_email_takes_over(): void
    {
        Mail::fake();
        $owner = $this->owner(['personal_email' => 'me@private.ph', 'personal_email_verified_at' => now()]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/auth/me')
            ->assertJsonPath('data.code_email', 'me@private.ph')
            ->assertJsonPath('data.code_email_is_sign_in', false);

        $this->postJson('/api/business/account/two-factor/enable')->assertOk()->assertJsonPath('sent_to', 'me@private.ph');
        Mail::assertSent(TwoFactorSetupOtpMail::class, fn ($mail) => $mail->hasTo('me@private.ph'));
    }

    public function test_a_personal_email_still_waiting_for_its_code_does_not_take_over(): void
    {
        $owner = $this->owner(['personal_email' => 'me@private.ph', 'personal_email_verified_at' => null]);

        $this->assertSame('owner@lotusleaf.ph', $owner->codeEmail());
        $this->assertTrue($owner->codesGoToSignInEmail());
    }
}
