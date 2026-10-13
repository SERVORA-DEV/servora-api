<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionReminderMail;
use App\Models\SpaBusinessSetting;
use App\Models\Subscription;
use App\Models\SystemSetting;
use App\Repository\SubscriptionRepository;
use App\Service\NotificationService;
use App\Support\SubscriptionReminderSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Scheduled daily (see bootstrap/app.php's withSchedule) — reminds each
// business owner about their Servora plan (or free trial): before it is due,
// while it is overdue, and once when access is paused. WHEN each of those
// goes out is SubscriptionReminderSchedule's job; this fetches the
// subscriptions in range, asks it, and sends to the bell and by email.
//
// The administrator sets the schedule (Settings > Billing). An owner can only
// switch off the reminders BEFORE the due date (Settings > Notifications);
// overdue and access-paused notices always go out.
//
// NOTE: this only fires automatically if the actual deployment runs
// `php artisan schedule:run` every minute via a real OS cron / Windows Task
// Scheduler entry — that's an ops/deployment step outside this codebase. In
// this dev environment, run it directly: `php artisan subscriptions:notify-almost-due`.
class NotifyAlmostDueSubscriptions extends Command
{
    protected $signature = 'subscriptions:notify-almost-due';
    protected $description = 'Send plan reminders (before due, overdue, access paused) to business owners';

    public function handle(SubscriptionRepository $subscriptions, NotificationService $notifications): int
    {
        $schedule = SubscriptionReminderSchedule::fromSettings(SystemSetting::current());
        $now = now();
        $sent = [];

        foreach ($subscriptions->findInReminderWindow($schedule->firstDays, $schedule->graceDays) as $subscription) {
            $owner = $subscription->business?->owner;
            if (! $owner) {
                continue;
            }

            $moment = $schedule->due(
                $subscription->expires_at,
                $subscription->expiry_reminder_sent_at,
                $now,
                (bool) $subscription->is_trial,
                // Auto-renew only means "nothing to do" while there is a
                // saved method to charge.
                $subscription->auto_renew && $subscription->paymentMethod !== null,
                $this->ownerWantsEarlyReminders($subscription),
            );
            if ($moment === null) {
                continue;
            }

            [$title, $message] = $notifications->subscriptionReminder($subscription, $moment, $schedule->graceDays);
            $this->email($owner->email, new SubscriptionReminderMail($title, $message, $subscription->is_trial ? 'Choose a plan' : 'Open my subscription', in_array($moment, [SubscriptionReminderSchedule::OVERDUE, SubscriptionReminderSchedule::ENDED], true)));

            $subscription->update(['expiry_reminder_sent_at' => $now]);
            $sent[$moment] = ($sent[$moment] ?? 0) + 1;
        }

        $this->info($sent
            ? 'Sent ' . array_sum($sent) . ' plan reminder(s): ' . collect($sent)->map(fn ($n, $moment) => "{$n} {$moment}")->implode(', ') . '.'
            : 'No plan reminders due.');

        return self::SUCCESS;
    }

    // The owner's Settings → Notifications → "Remind me before my plan is
    // due". It covers the reminders before the due date only.
    private function ownerWantsEarlyReminders(Subscription $subscription): bool
    {
        $prefs = $subscription->business?->settings?->section('notifications') ?? SpaBusinessSetting::DEFAULTS['notifications'];

        return (bool) $prefs['subscription_renewal_alert'];
    }

    // Email is a network call to a third party; a failure there is logged
    // and never stops the bell notification or the rest of the run.
    private function email(?string $address, SubscriptionReminderMail $mail): void
    {
        if (! $address) {
            return;
        }

        try {
            Mail::to($address)->send($mail);
        } catch (\Throwable $e) {
            Log::error('Failed to send plan reminder email', ['email' => $address, 'exception' => $e->getMessage()]);
        }
    }
}
