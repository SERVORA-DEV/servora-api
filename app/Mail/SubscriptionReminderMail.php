<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// A plan reminder to a business owner: the same title and sentence as the
// bell notification (NotificationService::subscriptionReminderText), with a
// button to their Subscription page. Sent by NotifyAlmostDueSubscriptions.
//
// Deliberately NOT ShouldQueue, like the OTP mails: there is no queue worker,
// and sending directly keeps an SMTP failure where the sender can catch it.
class SubscriptionReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $actionUrl;

    public function __construct(
        public string $heading,
        public string $body,
        public string $actionLabel,
        // Overdue and access-paused notices can't be switched off; the
        // reminders before the due date can, and the email says where.
        public bool $alwaysSent = false,
    ) {
        $this->actionUrl = rtrim((string) config('app.frontend_url'), '/') . '/business/subscription';
    }

    public function build()
    {
        return $this->subject($this->heading)
            ->markdown('emails.subscription.reminder');
    }
}
