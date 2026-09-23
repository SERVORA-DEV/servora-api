<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Repository\SubscriptionRepository;
use App\Service\NotificationService;
use Illuminate\Console\Command;

// Scheduled daily (see bootstrap/app.php's withSchedule) — sends a renewal
// reminder to each business owner whose subscription is expiring within the
// admin-configured window (Settings > Billing). With "repeat until paid" on,
// it keeps reminding every N days — through the grace period as "payment
// overdue" reminders — until a payment starts a new subscription row. See
// SubscriptionRepository::findDueForReminder for the exact rules.
//
// NOTE: this only fires automatically if the actual deployment runs
// `php artisan schedule:run` every minute via a real OS cron / Windows Task
// Scheduler entry — that's an ops/deployment step outside this codebase. In
// this dev environment, run it directly: `php artisan subscriptions:notify-almost-due`.
class NotifyAlmostDueSubscriptions extends Command
{
    protected $signature = 'subscriptions:notify-almost-due';
    protected $description = 'Send renewal / payment-overdue reminders to owners whose subscription is due';

    public function handle(SubscriptionRepository $subscriptions, NotificationService $notifications): int
    {
        $settings = SystemSetting::current();

        if (! $settings->almost_due_notify_enabled) {
            $this->info('Renewal reminders are disabled in Settings > Billing — nothing sent.');
            return self::SUCCESS;
        }

        $due = $subscriptions->findDueForReminder(
            $settings->almost_due_notify_days_before,
            $settings->almost_due_repeat_enabled,
            $settings->almost_due_repeat_every_days,
            $settings->subscription_grace_period_days,
        );

        foreach ($due as $subscription) {
            $notifications->subscriptionAlmostDue($subscription, $settings->subscription_grace_period_days);
            $subscription->update(['expiry_reminder_sent_at' => now()]);
        }

        $this->info("Sent {$due->count()} renewal reminder(s).");
        return self::SUCCESS;
    }
}
