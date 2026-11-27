<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionReminderMail;
use App\Models\SpaBusinessSetting;
use App\Models\Subscription;
use App\Models\SystemSetting;
use App\Repository\SubscriptionRepository;
use App\Service\Notifications\NotificationService;
use App\Support\Platform\SubscriptionReminderSchedule;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotifyAlmostDueSubscriptions extends Command
{
    protected $signature = 'subscriptions:notify-almost-due {--retry-email : Retry pending reminder emails}';

    protected $description = 'Send subscription reminders to business owners on the web and by email';

    public function handle(SubscriptionRepository $subscriptions, NotificationService $notifications): int
    {
        $schedule = SubscriptionReminderSchedule::fromSettings(SystemSetting::current());
        $now = now();
        $retryOnly = (bool) $this->option('retry-email');
        $candidates = $subscriptions->inReminderWindow($schedule->firstDays, $schedule->graceDays, $now)
            ->when($retryOnly, fn ($query) => $query->whereNotNull('expiry_reminder_email_moment'));
        $webCount = $emailCount = $failures = 0;

        foreach ((clone $candidates)->select('id')->lazyById(200) as $candidate) {
            try {
                // The daily run and email retry can overlap. Recheck the term and
                // delivery state after acquiring their shared lock.
                Cache::store('durable')->lock("subscription-reminder:{$candidate->id}", 600)->get(
                    function () use ($candidate, $candidates, $schedule, $now, $retryOnly, $notifications, &$webCount, &$emailCount) {
                        $subscription = (clone $candidates)
                            ->with(['business.owner', 'business.settings', 'plan', 'pendingPlan', 'scheduledPlan', 'paymentMethod'])
                            ->find($candidate->id);

                        if (! $subscription?->business?->owner) {
                            return;
                        }

                        $args = [
                            $now,
                            $subscription->is_trial,
                            $subscription->auto_renew && $subscription->paymentMethod !== null,
                            $this->ownerWantsEarlyReminders($subscription),
                        ];
                        if (! $retryOnly && ($moment = $schedule->due($subscription->expires_at, $subscription->expiry_reminder_sent_at, ...$args))) {
                            DB::transaction(function () use ($notifications, $subscription, $moment, $schedule, $now) {
                                $notifications->subscriptionReminder($subscription, $moment, $schedule->graceDays, $now);
                                $subscription->forceFill([
                                    'expiry_reminder_sent_at' => $now,
                                    'expiry_reminder_email_moment' => $moment,
                                ])->saveQuietly();
                            });
                            $webCount++;
                        }

                        // An old email is superseded when the plan reaches a new
                        // reminder moment. Rebuild the copy so dates stay accurate
                        // even when a retry happens later in the same window.
                        $currentMoment = $schedule->due($subscription->expires_at, null, ...$args);
                        $emailCount += (int) $this->sendPendingEmail($subscription, $currentMoment, $schedule, $now, $notifications);
                    }
                );
            } catch (Throwable $error) {
                report($error);
                $failures++;
            }
        }

        $this->info("Sent {$webCount} web reminder(s) and {$emailCount} email(s).");

        return $failures ? self::FAILURE : self::SUCCESS;
    }

    private function ownerWantsEarlyReminders(Subscription $subscription): bool
    {
        $prefs = $subscription->business->settings?->section('notifications') ?? SpaBusinessSetting::DEFAULTS['notifications'];

        return (bool) $prefs['subscription_renewal_alert'];
    }

    private function sendPendingEmail(Subscription $subscription, ?string $currentMoment, SubscriptionReminderSchedule $schedule, Carbon $now, NotificationService $notifications): bool
    {
        $pending = $subscription->expiry_reminder_email_moment;
        if (! $pending) {
            return false;
        }
        if ($pending !== $currentMoment) {
            $subscription->forceFill(['expiry_reminder_email_moment' => null])->saveQuietly();

            return false;
        }

        $address = trim((string) $subscription->business->owner->email);
        if ($address === '') {
            return false;
        }

        [$title, $message] = $notifications->subscriptionReminderText($subscription, $pending, $schedule->graceDays, $now);
        try {
            Mail::to($address)->send(new SubscriptionReminderMail(
                $title,
                $message,
                $subscription->is_trial ? 'View paid plans' : 'Open my subscription',
                in_array($pending, [SubscriptionReminderSchedule::OVERDUE, SubscriptionReminderSchedule::ENDED], true),
                $subscription->is_trial ? '/business/plans?paid=1' : '/business/subscription',
            ));
        } catch (Throwable $error) {
            Log::warning('Subscription reminder email pending after delivery error.', [
                'subscription_id' => $subscription->id,
                'exception' => $error::class,
            ]);

            return false;
        }

        $subscription->forceFill(['expiry_reminder_email_moment' => null])->saveQuietly();

        return true;
    }
}
