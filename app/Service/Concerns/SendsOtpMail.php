<?php

namespace App\Service\Concerns;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Single place every outbound OTP mail goes through, shared by UserService
 * (registration/password-reset OTPs) and SecurityService (personal-email
 * verification OTP). SMTP is a network call to a third party that can fail
 * for reasons that have nothing to do with the caller — a wrong app
 * password, Gmail's daily cap, a dropped connection — and none of those
 * should surface as a 500 on a request whose database work already
 * succeeded.
 *
 * Never logs the mailable's contents: these carry live OTPs, and
 * storage/logs is not where those belong.
 */
trait SendsOtpMail
{
    private function deliver(string $email, Mailable $mail, string $context): bool
    {
        try {
            Mail::to($email)->send($mail);

            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send {$context} email", [
                'email' => $email,
                'mailable' => $mail::class,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
