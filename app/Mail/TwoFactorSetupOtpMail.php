<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// The code that turns two-factor on (SecurityService::enableTwoFactor): it
// proves the personal email can be reached before sign-in starts depending
// on it. Deliberately NOT ShouldQueue — same reasoning as
// PersonalEmailVerificationOtpMail.
class TwoFactorSetupOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public int $expiresInMinutes,
    ) {}

    public function build()
    {
        return $this->subject('Turn On Two-Factor Authentication')
            ->markdown('emails.auth.two-factor-setup-otp');
    }
}
