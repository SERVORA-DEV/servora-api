<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Mail\AccountNoticeMail;
use App\Models\User;
use App\Support\Access\AccountScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendWebInactivityReminders extends Command
{
    protected $signature = 'accounts:send-web-inactivity-reminders';

    protected $description = 'Email inactive Servora web accounts once per period of inactivity';

    public function handle(): int
    {
        $days = (int) config('mail.web_inactivity_reminder_days');
        if ($days < 1) {
            return self::SUCCESS;
        }

        $now = now();
        $cutoff = $now->copy()->subDays($days);
        $eligible = User::query()
            ->whereIn('role', User::BUSINESS_WEB_ROLES)
            ->where('account_status', AccountStatus::Active->value)
            ->whereNotNull('email_verified_at')
            ->where('email', '<>', '')
            ->whereNull('deleted_at')
            ->whereNull('plan_hold_at')
            ->whereNull('inactivity_reminder_sent_at')
            ->whereRaw('COALESCE(last_web_activity_at, last_login_at, created_at) <= ?', [$cutoff])
            // Existing sessions and sign-in history cover accounts that the web app hasn't tracked yet.
            ->where(function ($query) use ($cutoff) {
                $query->whereNotNull('last_web_activity_at')->orWhere(function ($legacy) use ($cutoff) {
                    $legacy->whereDoesntHave('tokens', fn ($tokens) => $tokens->where(fn ($dates) => $dates
                        ->where('last_used_at', '>', $cutoff)->orWhere('created_at', '>', $cutoff)))
                        ->whereNotExists(fn ($history) => $history->from('audit_logs')
                            ->whereColumn('audit_logs.user_id', 'users.id')
                            ->where('table_name', 'users')
                            ->where('created_at', '>', $cutoff)
                            ->where(fn ($actions) => $actions->where('action', 'Login')
                                ->orWhere(fn ($logout) => $logout->where('action', 'Logout')
                                    ->where('new_values->reason', 'signed_out'))));
                });
            });

        $sent = 0;
        foreach ((clone $eligible)->lazyById() as $user) {
            $path = '/auth/login';
            if ($user->role !== 'business_owner') {
                $business = AccountScope::business($user);
                if (! $business) {
                    continue;
                }
                $path = '/login/'.$business->uuid;
            }

            // Check eligibility again when claiming the send. The account may
            // have returned to the website or another run may have claimed it.
            $claimed = (clone $eligible)->whereKey($user->id)->where('email', $user->email)
                ->toBase()->update(['inactivity_reminder_sent_at' => $now]);
            if (! $claimed) {
                continue;
            }

            try {
                Mail::to($user->email)->send(new AccountNoticeMail(
                    'Come back to SERVORA',
                    'It’s been a while since you visited SERVORA. Sign in to pick up where you left off.',
                    'Open SERVORA',
                    $path,
                ));
                $sent++;
            } catch (Throwable $error) {
                // Leave this account eligible for the next run after a mail failure.
                User::query()->whereKey($user->id)->where('inactivity_reminder_sent_at', $now)
                    ->toBase()->update(['inactivity_reminder_sent_at' => null]);
                report($error);
            }
        }

        $this->info("Sent {$sent} web inactivity reminder(s).");

        return self::SUCCESS;
    }
}
