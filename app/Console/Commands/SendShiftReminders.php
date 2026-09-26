<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\SpaBusinessSetting;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Repository\NotificationRepository;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendShiftReminders extends Command
{
    protected $signature = 'staff:send-shift-reminders';

    protected $description = "Remind managers and front-desk staff before their shift starts, per the spa's Settings > Notifications.";

    private const TITLE = 'Shift reminder';

    public function handle(NotificationRepository $notifications): int
    {
        $sent = 0;

        // Only accounts that can sign in have a feed — therapists don't.
        Staff::query()
            ->where('status', 'active')
            ->whereIn('role', array_keys(Staff::ACCOUNT_ROLE_MAP))
            ->whereNotNull('user_id')
            ->with('branch.business.settings')
            ->chunkById(200, function ($staff) use ($notifications, &$sent) {
                foreach ($staff as $member) {
                    $sent += $this->remind($member, $notifications) ? 1 : 0;
                }
            });

        $this->info("Sent {$sent} shift reminder(s).");

        return self::SUCCESS;
    }

    private function remind(Staff $member, NotificationRepository $notifications): bool
    {
        $settings = $member->branch?->business?->settings?->section('notifications')
            ?? SpaBusinessSetting::DEFAULTS['notifications'];
        if (! $settings['send_staff_reminder'] || ! $settings['in_app_channel']) {
            return false;
        }

        $lead = (int) $settings['staff_reminder_timing'];

        // Today's and tomorrow's shift — a long lead time can reach a shift
        // that starts early the next morning.
        foreach ([now()->startOfDay(), now()->addDay()->startOfDay()] as $day) {
            $startsAt = $this->shiftStart($member, $day);
            if (! $startsAt || $startsAt->isPast() || $startsAt->gt(now()->addHours($lead))) {
                continue;
            }

            // Any reminder written since this shift's window opened is the
            // reminder for this shift.
            $alreadySent = Notification::where('user_id', $member->user_id)
                ->where('title', self::TITLE)
                ->where('created_at', '>=', $startsAt->copy()->subHours($lead)->subMinutes(10))
                ->exists();
            if ($alreadySent) {
                continue;
            }

            $notifications->create(
                $member->user_id,
                self::TITLE,
                "Your shift at {$member->branch?->branch_name} starts {$startsAt->diffForHumans(['parts' => 1])} — "
                    . $startsAt->format('D, M j \\a\\t g:i A') . '.',
                'System',
            );

            return true;
        }

        return false;
    }

    private function shiftStart(Staff $member, Carbon $day): ?Carbon
    {
        $schedule = StaffSchedule::query()
            ->where('staff_id', $member->id)
            ->where('day_of_week', $day->format('l'))
            ->where('is_day_off', false)
            ->whereNotNull('start_time')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $day->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $day->toDateString()))
            // The most recent dated version wins over an undated default.
            ->orderByRaw('effective_from DESC NULLS LAST')
            ->first();

        return $schedule ? Carbon::parse($day->toDateString() . ' ' . $schedule->start_time) : null;
    }
}
