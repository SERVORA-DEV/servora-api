<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// The code that proves an owner can read the new email they asked for
// (OwnerIdentityChangeService). Not queued, like the other OTP mails: there
// is no queue worker, and SendsOtpMail catches a failed send.
class IdentityChangeEmailOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public int $expiresInMinutes,
    ) {}

    public function build()
    {
        return $this->subject('Confirm your new SERVORA email')
            ->markdown('emails.auth.identity-change-otp');
    }
}
