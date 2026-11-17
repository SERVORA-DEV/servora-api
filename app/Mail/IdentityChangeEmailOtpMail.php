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
        $code = e($this->otp);
        $minutes = (int) $this->expiresInMinutes;

        return $this->subject('Confirm your new SERVORA email')
            ->html(<<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;color:#233525">
  <p style="font-size:15px">You asked to change the email on your SERVORA owner account to this address.</p>
  <p style="font-size:15px">Enter this code to confirm it:</p>
  <p style="font-size:30px;font-weight:bold;letter-spacing:6px;margin:18px 0">{$code}</p>
  <p style="font-size:13px;color:#5f7155">It works for {$minutes} minutes. Your email changes only after the SERVORA team checks your new ID and face scan.</p>
  <p style="font-size:13px;color:#5f7155">If you didn't ask for this, you can ignore this email.</p>
</div>
HTML);
    }
}
