<?php

namespace App\Console\Commands;

use App\Models\SpaBusinessSetting;
use App\Models\Subscription;
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
    // The longest "remind me N days before" an owner can choose (Settings →
    // Notifications → Plan renewal alert).
    private const MAX_OWNER_DAYS = 30;

    protected $signature = 'subscriptions:notify-almost-due';
    protected $description = 'Send renewal / payment-overdue reminders to owners whose subscription is due';

    public function handle(SubscriptionRepository $subscriptions, NotificationService $notifications): int
    {
        $settings = SystemSetting::current();

        if (! $settings->almost_due_notify_enabled) {
            $this->info('Renewal reminders are disabled in Settings > Billing — nothing sent.');
            return self::SUCCESS;
        }

        // Fetched over the widest window an owner can pick (30 days), then
        // narrowed per business below by that owner's own preference.
        $adminDays = (int) $settings->almost_due_notify_days_before;
        $due = $subscriptions->findDueForReminder(
            max($adminDays, self::MAX_OWNER_DAYS),
            $settings->almost_due_repeat_enabled,
            $settings->almost_due_repeat_every_days,
            $settings->subscription_grace_period_days,
        )->filter(fn ($subscription) => $this->ownerWantsReminder($subscription, $adminDays))->values();

        foreach ($due as $subscription) {
            $notifications->subscriptionAlmostDue($subscription, $settings->subscription_grace_period_days);
            $subscription->update(['expiry_reminder_sent_at' => now()]);
        }

        $this->info("Sent {$due->count()} renewal reminder(s).");
        return self::SUCCESS;
    }

    // The owner's Settings → Notifications → Plan renewal alert. Before the
    // plan expires: none at all when switched off, and only within the days
    // they picked (the admin's Settings > Billing window until an owner has
    // saved their own). Once it has lapsed the "payment overdue" reminders
    // always go out — they're about losing access, not a courtesy.
    private function ownerWantsReminder(Subscription $subscription, int $adminDays): bool
    {
        if ($subscription->expires_at->isPast()) {
            return true;
        }

        $settings = $subscription->business?->settings;
        $prefs = $settings?->section('notifications') ?? SpaBusinessSetting::DEFAULTS['notifications'];
        if (! $prefs['subscription_renewal_alert']) {
            return false;
        }

        $days = array_key_exists('renewal_alert_days', (array) ($settings?->notifications ?? []))
            ? (int) $prefs['renewal_alert_days']
            : $adminDays;

        return $subscription->expires_at->lte(now()->addDays($days));
    }
}
