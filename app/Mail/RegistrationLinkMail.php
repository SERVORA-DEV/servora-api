<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// "Confirm your email" for someone signing up: the button creates the account
// (App\Support\PendingRegistration). Deliberately NOT ShouldQueue, like the
// other mails: there is no queue worker, and sending directly keeps an SMTP
// failure where the sender can catch it.
class RegistrationLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $url,
        public int $hours,
    ) {}

    public function build()
    {
        return $this->subject('Confirm your email to create your account')
            ->markdown('emails.auth.registration-link');
    }
}
