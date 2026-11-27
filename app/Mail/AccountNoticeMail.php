<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class AccountNoticeMail extends Mailable
{
    public string $actionUrl;

    public function __construct(
        public string $heading,
        public string $body,
        public string $actionLabel,
        string $actionPath,
        public ?string $recordName = null,
    ) {
        $this->actionUrl = rtrim((string) config('app.canonical_frontend_url'), '/').$actionPath;
    }

    public function build(): static
    {
        return $this->subject($this->heading)->markdown('emails.account.notice');
    }
}
