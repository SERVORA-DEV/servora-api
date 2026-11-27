<?php

namespace Tests\Feature;

use App\Mail\AccountNoticeMail;
use App\Mail\ContactEmailVerificationMail;
use App\Mail\IdentityChangeEmailOtpMail;
use App\Mail\PasswordResetOtpMail;
use App\Mail\PersonalEmailVerificationOtpMail;
use App\Mail\RegistrationLinkMail;
use App\Mail\RegistrationOtpMail;
use App\Mail\SubscriptionReminderMail;
use App\Mail\TwoFactorLoginOtpMail;
use App\Mail\TwoFactorSetupOtpMail;
use Illuminate\Mail\Mailable;
use Tests\TestCase;

// Every SERVORA email, as built: one look (the SERVORA wordmark leading to the website, the
// servora theme, the same footer) and wording for its purpose. Renders only — nothing is sent.
class MailFormatTest extends TestCase
{
    private const CODE = '482913';

    private const LINK = 'https://servora.test/confirm/abc';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.frontend_url' => 'https://app.servora.test',
            'app.canonical_frontend_url' => 'https://app.servora.test',
            'legal.operator.email' => '[Contact email]',
        ]);
    }

    /** @return array<string, array{0: Mailable, 1: string, 2: string, 3: bool}> mail, subject, the code or link it carries, whether it is a code email */
    private function mails(): array
    {
        return [
            'sign-up code' => [new RegistrationOtpMail(self::CODE, 10), 'Your SERVORA sign-up code', self::CODE, true],
            'sign-up link' => [new RegistrationLinkMail(self::LINK, 24), 'Confirm your email to create your SERVORA account', self::LINK, false],
            'password reset' => [new PasswordResetOtpMail(self::CODE, 10), 'Your SERVORA password reset code', self::CODE, true],
            'sign-in code' => [new TwoFactorLoginOtpMail(self::CODE, 10), 'Your SERVORA sign-in code', self::CODE, true],
            'two-factor setup' => [new TwoFactorSetupOtpMail(self::CODE, 10), 'Confirm two-factor sign-in', self::CODE, true],
            'recovery email' => [new PersonalEmailVerificationOtpMail(self::CODE, 10), 'Confirm your recovery email', self::CODE, true],
            'new owner email' => [new IdentityChangeEmailOtpMail(self::CODE, 10), 'Confirm your new SERVORA email', self::CODE, true],
            'contact email' => [new ContactEmailVerificationMail('Lotus Spa', 'your business', 'hello@lotus.test', self::LINK, 2), 'Confirm the contact email for Lotus Spa', self::LINK, false],
            'plan reminder' => [new SubscriptionReminderMail('Your plan renews in 3 days', 'Your Basic plan renews on December 1.', 'Open my subscription'), 'Your plan renews in 3 days', 'https://app.servora.test/business/subscription', false],
            'plan renewed' => [new AccountNoticeMail('Subscription Renewed', 'Your Enterprise plan renewed automatically.', 'View my subscription', '/business/subscription'), 'Subscription Renewed', 'https://app.servora.test/business/subscription', false],
        ];
    }

    public function test_every_email_has_its_subject_its_code_or_link_and_the_servora_look(): void
    {
        foreach ($this->mails() as $name => [$mail, $subject, $carries, $isCode]) {
            $mail->assertHasSubject($subject);

            $html = $mail->render();
            $this->assertStringContainsString($carries, $html, "$name: html carries it");
            $mail->assertSeeInText($carries);

            // The wordmark leads to the website (not the API), in the theme's forest green.
            $this->assertMatchesRegularExpression('#<a href="https://app\.servora\.test"[^>]*class="brand"[^>]*>SERVORA</a>#', $html, "$name: header");
            $this->assertStringContainsString('#233525', $html, "$name: servora theme");
            $this->assertStringNotContainsString('Laravel', $html, "$name: no Laravel");
            $this->assertStringNotContainsString('All rights reserved', $html, "$name: new footer");
            $this->assertStringContainsString('The SERVORA team', $html, "$name: sign-off");
            $this->assertStringNotContainsString('Thanks,', $html, "$name: old sign-off gone");
            // A placeholder contact address is never shown.
            $this->assertStringNotContainsString('[Contact email]', $html, "$name: no placeholder");

            if ($isCode) {
                $this->assertStringContainsString('class="code-cell"', $html, "$name: code panel");
                $this->assertStringContainsString("Don't share it with anyone", $html, "$name: don't share");
                $this->assertStringContainsString('10 minutes', $html, "$name: expiry");
            }
        }
    }

    public function test_the_footer_names_a_real_contact_address_once_one_is_set(): void
    {
        config(['legal.operator.email' => 'help@servora.test']);

        $mail = new PasswordResetOtpMail(self::CODE, 10);

        $this->assertStringContainsString('mailto:help@servora.test', $mail->render());
        $mail->assertSeeInText('help@servora.test');
    }

    public function test_the_plain_text_part_is_readable(): void
    {
        $mail = new PasswordResetOtpMail(self::CODE, 10);

        $mail->assertSeeInText('Reset your password');
        $mail->assertSeeInText(self::CODE);
        $mail->assertDontSeeInText('<table');
        $mail->assertDontSeeInText('code-cell');
    }

    public function test_the_contact_email_says_how_long_its_link_works(): void
    {
        $this->assertStringContainsString('The link expires in 2 days.', (new ContactEmailVerificationMail('Lotus Spa', 'your business', 'hello@lotus.test', self::LINK, 2))->render());
        $this->assertStringContainsString('The link expires in 1 day.', (new ContactEmailVerificationMail('Lotus Spa', 'your business', 'hello@lotus.test', self::LINK, 1))->render());
    }

    public function test_a_plan_reminder_says_why_it_was_sent(): void
    {
        $before = new SubscriptionReminderMail('Your plan renews in 3 days', 'Your Basic plan renews on December 1.', 'Open my subscription');
        $overdue = new SubscriptionReminderMail('Your payment is overdue', 'Pay to keep your access.', 'Open my subscription', true);

        $this->assertStringContainsString('Business Settings → Notifications', $before->render());
        $this->assertStringContainsString('it affects your access to SERVORA', $overdue->render());
    }
}
