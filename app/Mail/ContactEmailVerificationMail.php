<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// "Confirm this email" for the business's or a branch's contact email, with a
// button that confirms it (App\Support\ContactEmail). Deliberately NOT
// ShouldQueue, like the other mails: there is no queue worker, and sending
// directly keeps an SMTP failure where the sender can catch it.
class ContactEmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $what,
        public string $email,
        public string $url,
    ) {}

    public function build()
    {
        return $this->subject('Confirm the email for ' . $this->name)
            ->markdown('emails.contact-email-verify');
    }
}
