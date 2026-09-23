<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// Deliberately NOT ShouldQueue — same reasoning as PersonalEmailVerificationOtpMail.
class TwoFactorLoginOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public int $expiresInMinutes,
    ) {}

    public function build()
    {
        return $this->subject('Your Sign-In Code')
            ->markdown('emails.auth.two-factor-login-otp');
    }
}
