<?php

namespace Tests\Feature;

use App\Mail\ContactEmailVerificationMail;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Support\Branches\ContactEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// A branch email has to be confirmed by the button in the email Servora sends
// to it. The send is reported truthfully (a mail server that can't be reached
// is "failed", not "sent"), and until it is confirmed clients don't see it.
//
// Needs PostgreSQL (see phpunit.xml): the local throwaway database only.
class BranchEmailConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create(['role' => 'business_owner', 'email' => 'owner@lotusleaf.ph']);
        $business = SpaBusiness::factory()->create(['owner_id' => $owner->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $business->id, 'email' => null]);
    }

    public function test_a_new_branch_email_gets_the_confirm_email(): void
    {
        Mail::fake();
        $this->branch->forceFill(['email' => 'davao@lotusleaf.ph'])->save();

        $this->assertSame('sent', ContactEmail::afterSave($this->branch->fresh(), null));
        Mail::assertSent(ContactEmailVerificationMail::class, fn ($mail) => $mail->hasTo('davao@lotusleaf.ph') && str_contains($mail->url, 'signature='));
        $this->assertNull($this->branch->fresh()->email_verified_at);
    }

    public function test_a_mail_server_that_cannot_be_reached_is_reported_as_failed(): void
    {
        // Nothing listens on this port: the connection is refused at once.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 2,
        ]);
        $this->branch->forceFill(['email' => 'davao@lotusleaf.ph'])->save();

        $this->assertSame('failed', ContactEmail::afterSave($this->branch->fresh(), null));
        $this->assertFalse(ContactEmail::isVerified($this->branch->fresh()));
    }

    public function test_the_owners_own_sign_up_email_needs_no_confirmation(): void
    {
        Mail::fake();
        $this->branch->forceFill(['email' => 'Owner@LotusLeaf.ph'])->save();

        $this->assertSame('verified', ContactEmail::afterSave($this->branch->fresh(), null));
        Mail::assertNothingSent();
    }
}
