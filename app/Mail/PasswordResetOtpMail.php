<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// Deliberately NOT ShouldQueue: there is no queue worker, and with
// QUEUE_CONNECTION=sync a queued mailable runs inline anyway. Sending
// directly keeps the SMTP failure where UserService::deliver() can catch it.
class PasswordResetOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public int $expiresInMinutes,
    ) {}

    public function build()
    {
        return $this->subject('Your Password Reset Code')
            ->markdown('emails.auth.password-reset-otp');
    }
}
