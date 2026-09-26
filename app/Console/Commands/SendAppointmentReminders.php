<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\SpaBusinessSetting;
use App\Service\Business\ClientNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = "Notify clients shortly before a scheduled appointment, per the spa's Settings > Notifications.";

    // The longest lead time the owner can choose (see reminder_timing).
    private const MAX_LEAD_HOURS = 48;

    public function handle(ClientNotifier $notifier): int
    {
        $sent = 0;

        Appointment::query()
            ->where('status', Appointment::STATUS_SCHEDULED)
            ->whereNull('reminder_sent_at')
            ->whereBetween('appointment_date', [now()->toDateString(), now()->addHours(self::MAX_LEAD_HOURS)->toDateString()])
            ->whereHas('client', fn ($q) => $q->whereNotNull('user_id'))
            ->with('client', 'branch.business.settings')
            ->chunkById(200, function ($appointments) use ($notifier, &$sent) {
                foreach ($appointments as $appointment) {
                    $sent += $this->remind($appointment, $notifier) ? 1 : 0;
                }
            });

        $this->info("Sent {$sent} appointment reminder(s).");

        return self::SUCCESS;
    }

    private function remind(Appointment $appointment, ClientNotifier $notifier): bool
    {
        $business = $appointment->branch?->business;
        $settings = $business?->settings?->section('notifications') ?? SpaBusinessSetting::DEFAULTS['notifications'];
        if (! $settings['send_client_reminder'] || ! $settings['in_app_channel']) {
            return false;
        }

        $startsAt = Carbon::parse($appointment->appointment_date->toDateString().' '.$appointment->appointment_time);
        $leadHours = (int) $settings['reminder_timing'];

        if ($startsAt->isPast() || $startsAt->gt(now()->addHours($leadHours))) {
            return false;
        }

        // Booked inside the reminder window already — they know, don't nag.
        $alreadyKnows = $appointment->created_at && $appointment->created_at->gte($startsAt->copy()->subHours($leadHours));
        if (! $alreadyKnows) {
            $notifier->appointment(
                $appointment,
                'Appointment reminder',
                "Your booking {$appointment->appointment_number} at {$appointment->branch?->branch_name} is "
                    . $startsAt->diffForHumans(['parts' => 1]) . ' — ' . $startsAt->format('D, M j \\a\\t g:i A') . '.',
            );
        }

        $appointment->forceFill(['reminder_sent_at' => now()])->saveQuietly();

        return ! $alreadyKnows;
    }
}
